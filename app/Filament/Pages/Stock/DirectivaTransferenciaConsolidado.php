<?php

namespace App\Filament\Pages\Stock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Jobs\ExtraerKardexJob;
use App\Models\DirectivaTransferenciaSugerencia;
use App\Models\GuiaInternaSincronizacion;
use App\Models\KardexExtraccion as KardexExtraccionModel;
use App\Models\ProductoPresentacionDespacho;
use App\Services\DirectivaTransferenciaService;
use App\Services\GuiasInternasHistoricoService;
use App\Services\KardexGatewayClient;
use Dompdf\Dompdf;
use Dompdf\Options as DompdfOptions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Cantidad sugerida a despachar -- fase inicial de la Directiva de
 * Transferencia. Revisión completa a propósito (no por excepción todavía):
 * recién estamos empezando, hay que confirmar que el modelo acierta antes
 * de pasar a que solo se resalten los casos raros. Ver
 * DirectivaTransferenciaService para el criterio de cálculo.
 *
 * La pantalla trabaja SIEMPRE sobre despachos futuros (nunca hoy) -- pedido
 * explícito del usuario: la reposición real se decide con anticipación,
 * para poder enviarla a producción antes de que termine hoy, no a último
 * momento cuando ya casi no queda margen. El cálculo automático de las
 * 03:00am (ver routes/console.php) sigue corriendo aparte para "hoy" como
 * red de seguridad -- esta pantalla es la vía manual, disparable en
 * cualquier momento del día.
 *
 * OJO, cambio real del 2026-09-09: la fecha de despacho YA NO es una sola
 * fecha global ("mañana" para todos) -- cada local calcula la suya propia
 * según su frecuencia de reparto (`LocalLogisticaConfig.frecuencia_dias`,
 * ver DirectivaTransferenciaService). Un local diario cae en mañana; uno
 * que reparte cada 2 días cae en pasado mañana. Por eso la tabla filtra
 * "fecha_despacho >= mañana" (trae todas las fechas futuras que existan),
 * no una fecha exacta, y tiene su propia columna "Fecha despacho" para que
 * se distinga cuál es cuál.
 */
class DirectivaTransferenciaConsolidado extends Page implements HasTable
{
    use InteractsWithTable;
    use ScopesLocalsToUser;

    /**
     * Id de la extracción de Kardex y de la sincronización de Guías internas
     * que "Sincronizar y calcular para mañana" dejó en curso -- el poll de
     * la vista los revisa hasta que ambas terminan, y recién ahí dispara el
     * cálculo. Ver docblock de sincronizarYCalcularManana().
     */
    public ?int $kardexExtraccionId = null;

    public ?int $guiasSincronizacionId = null;

    public bool $sincronizandoParaManana = false;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';
    protected static ?string $navigationLabel = 'Directiva de transferencia';
    protected static ?string $title = 'Directiva de Transferencia -- Cantidad sugerida';
    protected static string|\UnitEnum|null $navigationGroup = 'Stock Inicial';
    protected static ?int $navigationSort = 5;
    protected static ?string $slug = 'stock-inicial/directiva-transferencia';
    protected string $view = 'filament.pages.stock.directiva-transferencia-consolidado';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('directiva-transferencia.view');
    }

    /**
     * "Hoy" -- la fecha de referencia que recibe
     * DirectivaTransferenciaService::calcularParaFecha(). Cada local calcula
     * su PROPIA fecha de despacho a partir de acá (hoy + su frecuencia_dias)
     * -- ya no hay una única "fecha de despacho" global, ver docblock de la
     * clase.
     */
    private function fechaReferencia(): string
    {
        return now()->toDateString();
    }

    /** La fecha más próxima que puede llegar a mostrar la tabla -- todo local, aun uno diario, cae mañana o después, nunca hoy. */
    private function fechaMinimaAMostrar(): string
    {
        return now()->addDay()->toDateString();
    }

    private function tableHeaderActions(): array
    {
        return [
            Action::make('exportarExcel')
                ->label('Exportar Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => $this->exportarExcel()),
            Action::make('exportarPdf')
                ->label('Exportar PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(fn () => $this->exportarPdf()),
            Action::make('recalcular')
                ->label('Recalcular para mañana')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('¿Recalcular la Directiva de mañana?')
                ->modalDescription('Vuelve a calcular la cantidad sugerida para todos los locales e ítems con la fecha de mañana, usando el saldo y el histórico de ventas TAL CUAL están guardados ahora mismo -- no sincroniza nada nuevo. Usa "Sincronizar y calcular para mañana" si además querés refrescar Kardex y Guías internas antes.')
                ->action(function (): void {
                    $total = app(DirectivaTransferenciaService::class)->calcularParaFecha($this->fechaReferencia());
                    Notification::make()->success()->title('Directiva recalculada')->body("{$total} sugerencias generadas para mañana.")->send();
                    $this->resetTable();
                }),
            Action::make('sincronizarYCalcular')
                ->label('Sincronizar y calcular para mañana')
                ->icon('heroicon-o-bolt')
                ->color('primary')
                ->visible(fn (): bool => ! $this->sincronizandoParaManana)
                ->requiresConfirmation()
                ->modalHeading('¿Sincronizar Kardex y Guías internas antes de calcular?')
                ->modalDescription('Actualiza primero el Kardex de HOY (saldo real) y las Guías internas más recientes (mercadería en tránsito) para todos los locales, y recién cuando ambas terminen calcula la Directiva de mañana con esos datos frescos. Puede tardar varios minutos -- esta pantalla se actualiza sola mientras tanto.')
                ->modalSubmitActionLabel('Sí, sincronizar y calcular')
                ->action(fn () => $this->sincronizarYCalcularManana()),
        ];
    }

    /**
     * Flujo completo pedido por el usuario: antes de calcular la Directiva
     * de mañana, refresca las 2 únicas fuentes de las que depende el
     * cálculo -- Kardex (saldo_actual) y Guías internas (cantidad_en_transito)
     * -- en vez de confiar en lo que haya quedado de sincronizaciones
     * anteriores. Los otros 4 módulos del Panel de Sincronización (Ventas,
     * Salidas de stock, Requerimientos, Movimientos entre almacenes) NO se
     * tocan acá: no alimentan esta fórmula, sincronizarlos solo agregaría
     * espera sin cambiar el resultado.
     *
     * Kardex normalmente solo extrae el día ANTERIOR (ver
     * SincronizarKardexDiario) porque Restaurant lo considera más estable
     * -- pero comprobado en vivo (2026-09-09) que extraer el día EN CURSO
     * sí devuelve datos reales (entradas/salidas ya registradas hasta el
     * momento de la consulta), así que acá se extrae explícitamente HOY,
     * no ayer -- es la mejor foto disponible del saldo antes de calcular
     * mañana.
     *
     * Ninguna de las dos sincronizaciones corre en el propio request web
     * (moriría al terminar el request, ver DespacharSincronizacionesPendientes)
     * -- Kardex se despacha directo a su cola dedicada (mismo patrón que el
     * botón manual de Kardex > Extracción); Guías internas solo se encola
     * como 'pendiente' y el despachador de cada minuto la toma. El poll de
     * la vista revisa el estado de ambas y recién cuando las dos terminan
     * dispara el cálculo -- ver verificarSincronizacionParaManana().
     */
    public function sincronizarYCalcularManana(): void
    {
        abort_unless(auth()->user()?->hasPermission('directiva-transferencia.view'), 403);

        $hoy = now()->toDateString();

        if (KardexExtraccionModel::query()->whereIn('estado', ['pendiente', 'en_progreso'])->exists()) {
            $this->kardexExtraccionId = KardexExtraccionModel::query()->whereIn('estado', ['pendiente', 'en_progreso'])->latest('id')->value('id');
        } else {
            try {
                $locales = app(KardexGatewayClient::class)->locals();
            } catch (Throwable $exception) {
                Notification::make()->danger()->title('No se pudo iniciar la sincronización de Kardex')->body($exception->getMessage())->send();

                return;
            }
            $localesIds = collect($locales)->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();
            $extraccion = KardexExtraccionModel::create([
                'estado' => 'pendiente',
                'filtros' => [
                    'locales' => implode('-', $localesIds),
                    'localesNombres' => collect($locales)->mapWithKeys(fn (array $l): array => [(string) $l['id'] => (string) ($l['name'] ?? '')])->all(),
                    'motivo' => '-1',
                    'fechaInicio' => $hoy,
                    'fechaFin' => $hoy,
                ],
                'iniciado_por' => auth()->id(),
            ]);
            ExtraerKardexJob::dispatch($extraccion->id)->onQueue('kardex');
            $this->kardexExtraccionId = $extraccion->id;
        }

        if (GuiaInternaSincronizacion::query()->whereIn('estado', ['pendiente', 'en_progreso'])->exists()) {
            $this->guiasSincronizacionId = GuiaInternaSincronizacion::query()->whereIn('estado', ['pendiente', 'en_progreso'])->latest('id')->value('id');
        } else {
            // Mismo criterio de ventana que el sync incremental automático de
            // cada 30 min (routes/console.php) -- 3 días hacia atrás, todos
            // los locales permitidos, hasta hoy.
            $run = app(GuiasInternasHistoricoService::class)->iniciar(now()->subDays(3)->toDateString(), $hoy, [], auth()->id());
            $this->guiasSincronizacionId = $run->id;
        }

        $this->sincronizandoParaManana = true;
        Notification::make()->info()->title('Sincronizando antes de calcular')
            ->body('Actualizando Kardex de hoy y Guías internas. En cuanto ambas terminen, la Directiva de mañana se calcula sola.')
            ->send();
    }

    /**
     * Llamada por wire:poll desde la vista mientras sincronizandoParaManana
     * es true. No se ata a NINGÚN progreso de "venta esperada de hoy" --
     * solo espera a que las 2 sincronizaciones que se dispararon terminen
     * (o fallen), y recién ahí calcula. Si una de las dos falla, se aborta
     * el cálculo automático en vez de correrlo con datos a medias -- mejor
     * que el usuario lo note y decida (reintentar, o usar "Recalcular para
     * mañana" con lo que ya haya quedado bueno).
     */
    public function verificarSincronizacionParaManana(): void
    {
        if (! $this->sincronizandoParaManana) {
            return;
        }

        $kardex = $this->kardexExtraccionId ? KardexExtraccionModel::find($this->kardexExtraccionId) : null;
        $guias = $this->guiasSincronizacionId ? GuiaInternaSincronizacion::find($this->guiasSincronizacionId) : null;

        // Guías internas puede cerrar en 'completado_con_errores' (algún
        // local puntual falló pero el resto sí se guardó) -- se trata igual
        // que 'completado' acá, no se queda esperando para siempre. Kardex
        // no tiene ese estado intermedio: para él, solo 'completado' cuenta.
        $kardexListo = ! $kardex || $kardex->estado === 'completado';
        $guiasListo = ! $guias || in_array($guias->estado, ['completado', 'completado_con_errores'], true);
        $kardexFallo = $kardex?->estado === 'fallido';
        $guiasFallo = $guias?->estado === 'fallido';

        if ($kardexFallo || $guiasFallo) {
            $this->sincronizandoParaManana = false;
            $detalle = $kardexFallo ? 'la extracción de Kardex' : 'la sincronización de Guías internas';
            Notification::make()->danger()->title('No se pudo sincronizar')
                ->body("Falló {$detalle}. Revisa el Panel de Sincronización -- no se calculó nada para no usar datos a medias.")
                ->send();

            return;
        }

        if ($kardexListo && $guiasListo) {
            $this->sincronizandoParaManana = false;
            $this->kardexExtraccionId = null;
            $this->guiasSincronizacionId = null;
            $total = app(DirectivaTransferenciaService::class)->calcularParaFecha($this->fechaReferencia());
            Notification::make()->success()->title('Directiva de mañana calculada')->body("{$total} sugerencias generadas con datos recién sincronizados.")->send();
            $this->resetTable();
        }
    }

    /**
     * Arma los 3 datasets del pivote (locales, productos, mapa local×item →
     * sugerencia) para todas las fechas de despacho futuras que existan --
     * compartido entre exportarExcel() y exportarPdf() para no duplicar el
     * mismo cruce dos veces. Cada local puede tener una fecha de despacho
     * distinta (ver docblock de la clase), así que esto trae TODAS las
     * fechas futuras juntas, no una sola.
     *
     * @return array{locales: \Illuminate\Support\Collection, productos: \Illuminate\Support\Collection, mapa: \Illuminate\Support\Collection}
     */
    private function pivotData(): array
    {
        $sugerenciasQuery = DirectivaTransferenciaSugerencia::query()->where('fecha_despacho', '>=', $this->fechaMinimaAMostrar());
        if (auth()->user()?->isRestrictedToLocals()) {
            $sugerenciasQuery->whereIn('local_id', auth()->user()->assignedLocalIds());
        }
        $sugerencias = $sugerenciasQuery->get();

        $locales = $sugerencias->unique('local_id')->sortBy('local_nombre')->values();
        // Mismo orden en el que se cargaron los productos (categorías
        // agrupadas: bocaditos, luego pollo, luego bebidas...) -- no
        // alfabético, para que el export se vea igual que la plantilla
        // original del usuario.
        $productos = ProductoPresentacionDespacho::query()->orderBy('id')->get()
            ->filter(fn (ProductoPresentacionDespacho $p) => $sugerencias->contains(fn ($s) => $s->item_id === $p->item_id && $s->item_tipo === $p->item_tipo));

        $mapa = $sugerencias->keyBy(fn ($s) => "{$s->local_id}|{$s->item_id}|{$s->item_tipo}");

        return compact('locales', 'productos', 'mapa');
    }

    /**
     * Consolidado pivote: filas = producto (con SKU), columnas = local, con
     * fila y columna TOTAL -- mismo formato que la plantilla que ya usa el
     * usuario para repartir stock inicial entre locales (una tabla, no una
     * lista larga). Los números son la cantidad sugerida de MAÑANA, no un
     * histórico -- se recalculan cada vez que se exporta.
     */
    private function exportarExcel(): StreamedResponse
    {
        ['locales' => $locales, 'productos' => $productos, 'mapa' => $mapa] = $this->pivotData();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Directiva Transferencia');

        $lastColumn = $locales->count() + 3;
        $lastColumnLetter = Coordinate::stringFromColumnIndex($lastColumn);
        $headerRow = 1;

        $sheet->setCellValue('A1', 'DIRECTIVA TRANSFERENCIA');
        $sheet->setCellValue('B1', 'SKU');
        foreach ($locales as $index => $local) {
            $sheet->setCellValue([$index + 3, $headerRow], $local->local_nombre);
        }
        $sheet->setCellValue([$lastColumn, $headerRow], 'TOTAL');

        $headerRange = "A{$headerRow}:{$lastColumnLetter}{$headerRow}";
        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DCE6F1');
        $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        foreach (range(3, $lastColumn) as $columnIndex) {
            $sheet->getStyle(Coordinate::stringFromColumnIndex($columnIndex).$headerRow)->getAlignment()->setTextRotation(90);
        }
        $sheet->getRowDimension($headerRow)->setRowHeight(120);

        $rowNumber = $headerRow + 1;
        $totalesColumna = array_fill(0, $locales->count(), 0.0);
        foreach ($productos as $producto) {
            $sheet->setCellValue([1, $rowNumber], $producto->item_nombre);
            $sheet->setCellValue([2, $rowNumber], $producto->item_codigo);
            $totalFila = 0.0;
            foreach ($locales as $index => $local) {
                $cantidad = (float) ($mapa->get("{$local->local_id}|{$producto->item_id}|{$producto->item_tipo}")?->cantidad_sugerida ?? 0);
                $sheet->setCellValue([$index + 3, $rowNumber], $cantidad);
                $totalFila += $cantidad;
                $totalesColumna[$index] += $cantidad;
            }
            $sheet->setCellValue([$lastColumn, $rowNumber], $totalFila);
            $rowNumber++;
        }

        $filaTotal = $rowNumber;
        $sheet->setCellValue([1, $filaTotal], 'TOTAL');
        $granTotal = 0.0;
        foreach ($locales as $index => $local) {
            $sheet->setCellValue([$index + 3, $filaTotal], $totalesColumna[$index]);
            $granTotal += $totalesColumna[$index];
        }
        $sheet->setCellValue([$lastColumn, $filaTotal], $granTotal);
        $sheet->getStyle("A{$filaTotal}:{$lastColumnLetter}{$filaTotal}")->getFont()->setBold(true);
        $sheet->getStyle("A{$filaTotal}:{$lastColumnLetter}{$filaTotal}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DCE6F1');

        $sheet->getStyle("A{$headerRow}:{$lastColumnLetter}{$filaTotal}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('FFB8CCE4'));
        $sheet->getStyle('C'.($headerRow + 1).":{$lastColumnLetter}{$filaTotal}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("{$lastColumnLetter}".($headerRow + 1).":{$lastColumnLetter}{$filaTotal}")->getFont()->setBold(true);

        $sheet->getColumnDimension('A')->setWidth(30);
        $sheet->getColumnDimension('B')->setWidth(10);
        foreach (range(3, $lastColumn) as $index) $sheet->getColumnDimensionByColumn($index)->setWidth(9);
        $sheet->freezePane('C'.($headerRow + 1));

        $writer = new Xlsx($spreadsheet);
        $filename = 'directiva-transferencia-'.$this->fechaMinimaAMostrar().'.xlsx';

        return response()->streamDownload(function () use ($writer, $spreadsheet): void {
            try { $writer->save('php://output'); } finally { $spreadsheet->disconnectWorksheets(); }
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /**
     * Mismo pivote que exportarExcel(), en PDF A3 apaisado -- mismo patrón
     * ya usado en Reporte de movimientos entre almacenes
     * (ReporteMovimientosAlmacenes::exportarPdf()). Pensado para enviar
     * directo a producción sin que nadie tenga que abrir Excel.
     */
    private function exportarPdf(): StreamedResponse
    {
        ['locales' => $locales, 'productos' => $productos, 'mapa' => $mapa] = $this->pivotData();

        $filas = $productos->map(function (ProductoPresentacionDespacho $producto) use ($locales, $mapa): array {
            $cantidades = $locales->map(fn ($local): float => (float) ($mapa->get("{$local->local_id}|{$producto->item_id}|{$producto->item_tipo}")?->cantidad_sugerida ?? 0));

            return [
                'nombre' => $producto->item_nombre,
                'codigo' => $producto->item_codigo,
                'cantidades' => $cantidades,
                'total' => $cantidades->sum(),
            ];
        });

        $totalesColumna = $locales->map(fn ($local, $index): float => $filas->sum(fn (array $fila) => $fila['cantidades'][$index]));
        $granTotal = $totalesColumna->sum();

        $options = new DompdfOptions();
        $options->set('isRemoteEnabled', false);
        $pdf = new Dompdf($options);
        $pdf->setPaper('a3', 'landscape');
        $pdf->loadHtml(view('filament.pages.stock.directiva-transferencia-pdf', [
            'fecha' => Carbon::parse($this->fechaMinimaAMostrar())->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY'),
            'locales' => $locales,
            'filas' => $filas,
            'totalesColumna' => $totalesColumna,
            'granTotal' => $granTotal,
        ])->render());
        $pdf->render();

        $filename = 'directiva-transferencia-'.$this->fechaMinimaAMostrar().'.pdf';

        return response()->streamDownload(fn () => print $pdf->output(), $filename, ['Content-Type' => 'application/pdf']);
    }

    public function table(Table $table): Table
    {
        return $table
            ->headerActions($this->tableHeaderActions())
            ->query(function (): Builder {
                $query = DirectivaTransferenciaSugerencia::query()
                    ->where('fecha_despacho', '>=', $this->fechaMinimaAMostrar());

                if (auth()->user()?->isRestrictedToLocals()) {
                    $query->whereIn('local_id', auth()->user()->assignedLocalIds());
                }

                return $query;
            })
            ->columns([
                TextColumn::make('local_nombre')->label('Local')->searchable()->sortable(),
                TextColumn::make('fecha_despacho')->label('Fecha despacho')->date('d/m/Y')->sortable()
                    ->tooltip('Puede variar por local según su frecuencia de reparto configurada.'),
                TextColumn::make('item_codigo')->label('SKU')->searchable(),
                TextColumn::make('item_nombre')->label('Producto')->searchable()->wrap(),
                TextColumn::make('demanda_promedio')->label('Demanda prom.')->numeric(2)->alignEnd()
                    ->tooltip(fn (DirectivaTransferenciaSugerencia $r): string => 'Promedio de las últimas '.$r->semanas_consideradas.' semanas, mismo tramo de horas real (día de semana + hora de llegada) que esta reposición.')
                    ->color(fn (DirectivaTransferenciaSugerencia $r): string => $r->esConfianzaBaja() ? 'gray' : 'success'),
                TextColumn::make('semanas_consideradas')->label('Semanas')->alignEnd()->toggleable()
                    ->badge()->color(fn (DirectivaTransferenciaSugerencia $r): string => $r->esConfianzaBaja() ? 'warning' : 'gray'),
                TextColumn::make('saldo_actual')->label('Saldo actual')->numeric(2)->alignEnd()
                    ->color(fn ($state): string => (float) $state < 0 ? 'danger' : 'gray'),
                TextColumn::make('cantidad_en_transito')->label('En tránsito')->numeric(2)->alignEnd()->toggleable()
                    ->tooltip('Guías internas con fecha de traslado entre hoy y la fecha de despacho de esta fila (ambas incluidas), todavía sin confirmar recepción -- ya sumadas al stock proyectado antes de calcular la sugerencia.')
                    ->color(fn ($state): string => (float) $state > 0 ? 'info' : 'gray'),
                TextColumn::make('multiplo_aplicado')->label('Múltiplo')->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cantidad_sugerida')->label('Cantidad sugerida')->numeric()->alignEnd()->sortable()
                    ->weight('bold')->color('primary')->badge(),
            ])
            ->filters([
                SelectFilter::make('local_id')->label('Local')
                    ->options(fn (): array => $this->scopeKeyedLocalsToUser(
                        DirectivaTransferenciaSugerencia::query()->where('fecha_despacho', '>=', $this->fechaMinimaAMostrar())
                            ->distinct()->pluck('local_nombre', 'local_id')->all(),
                    ))->searchable(),
                Filter::make('confianza_baja')->label('Confianza baja (< 3 semanas de histórico)')
                    ->query(fn (Builder $query): Builder => $query->where('semanas_consideradas', '<', 3)),
            ])
            ->defaultSort('fecha_despacho')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('Todavía no se calculó la Directiva de Transferencia de mañana -- usa "Recalcular para mañana" o "Sincronizar y calcular para mañana".');
    }
}
