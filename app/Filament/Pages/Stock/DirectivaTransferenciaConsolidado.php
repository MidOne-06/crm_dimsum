<?php

namespace App\Filament\Pages\Stock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Models\BrandingSetting;
use App\Models\DirectivaTransferenciaSugerencia;
use App\Models\ProductoPresentacionDespacho;
use App\Services\DirectivaTransferenciaService;
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
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
 * según sus propios días sin DT configurados (`LocalDiaSinDt`, ver
 * `DirectivaTransferenciaService::proximaLlegada()` -- reemplazó a
 * `LocalLogisticaConfig.frecuencia_dias`, que ya no se usa para esto). Un
 * local sin ninguna excepción cae en mañana, igual que siempre; uno con
 * algún día sin DT puede caer más adelante, saltando los días sin llegada.
 * Por eso la tabla filtra "fecha_despacho >= mañana" (trae todas las
 * fechas futuras que existan), no una fecha exacta, y tiene su propia
 * columna "Fecha despacho" para que se distinga cuál es cuál.
 */
class DirectivaTransferenciaConsolidado extends Page implements HasTable
{
    use InteractsWithTable;
    use ScopesLocalsToUser;

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
     * su PROPIA fecha de despacho a partir de acá, saltando sus días sin DT
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
            Action::make('iniciarDirectiva')
                ->label('Iniciar Directiva de Transferencia')
                ->icon('heroicon-o-play')
                ->color('success')
                ->url(fn (): string => \App\Filament\Pages\Stock\IniciarDirectivaTransferencia::getUrl()),
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
                ->modalDescription('Vuelve a calcular la cantidad sugerida para todos los locales e ítems con la fecha de mañana, usando el saldo y el histórico de ventas TAL CUAL están guardados ahora mismo -- no sincroniza nada nuevo. Usa el módulo "Iniciar Directiva de Transferencia" si además querés refrescar Kardex y Guías internas antes, o elegir el alcance.')
                ->action(function (): void {
                    $total = app(DirectivaTransferenciaService::class)->calcularParaFecha($this->fechaReferencia());
                    Notification::make()->success()->title('Directiva recalculada')->body("{$total} sugerencias generadas para mañana.")->send();
                    $this->resetTable();
                }),
        ];
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
     * Logo de la marca embebido como data URI -- dompdf corre con
     * `isRemoteEnabled(false)` (no puede pedir la imagen por HTTP), así que
     * hay que leer el archivo real del disco y embeberlo en base64 directo
     * en el HTML. Con logo subido usa ese PNG/JPG; sin logo cae al SVG de
     * marca por defecto (`public/images/crm-dimsum-mark.svg`).
     */
    private function logoDataUri(): ?string
    {
        $branding = BrandingSetting::current();

        if (filled($branding->logo_path) && Storage::disk('public')->exists($branding->logo_path)) {
            $ruta = Storage::disk('public')->path($branding->logo_path);
            $mime = File::mimeType($ruta) ?: 'image/png';

            return 'data:'.$mime.';base64,'.base64_encode(file_get_contents($ruta));
        }

        $rutaDefault = public_path('images/crm-dimsum-mark.svg');
        if (file_exists($rutaDefault)) {
            return 'data:image/svg+xml;base64,'.base64_encode(file_get_contents($rutaDefault));
        }

        return null;
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
            'logoDataUri' => $this->logoDataUri(),
            'usuarioNombre' => auth()->user()?->name ?? 'Sistema',
            'generadoEn' => now()->format('d/m/Y H:i'),
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
                    ->tooltip('Puede variar por local según sus días sin DT configurados (Configuración DT > Días sin DT).'),
                TextColumn::make('item_codigo')->label('SKU')->searchable(),
                TextColumn::make('item_nombre')->label('Producto')->searchable()->wrap(),
                // Pedido explícito del usuario (2026-09-09): esta columna debe
                // mostrar la demanda de lo que él realmente va a despachar --
                // el tramo 2 (mañana, DESPUÉS de que llegue el transporte,
                // hasta pasado mañana, cuando llega el pedido de hoy) -- no el
                // total combinado de los 2 tramos que se usa puertas adentro
                // para el cálculo. La fórmula de `cantidad_sugerida` NO
                // cambia: sigue restando el stock proyectado del total
                // combinado (`demanda_promedio`, columna de BD), que ya
                // arrastra correctamente cualquier sobrante/faltante del
                // tramo 1 hacia el tramo 2 -- ver DirectivaTransferenciaService.
                // Este es un cambio de qué NÚMERO SE MUESTRA, no de la
                // fórmula: tramo2 = total combinado - tramo 1, calculado al
                // vuelo desde los 2 campos que ya se guardan.
                TextColumn::make('demanda_tramo2')->label('Demanda prom.')->numeric(2)->alignEnd()
                    ->getStateUsing(fn (DirectivaTransferenciaSugerencia $r): float => $r->demanda_promedio - $r->demanda_ventana1)
                    ->tooltip(fn (DirectivaTransferenciaSugerencia $r): string => 'Promedio de las últimas '.$r->semanas_consideradas.' semanas -- SOLO el tramo que este despacho debe cubrir: desde que llega el transporte de mañana hasta que llega el de pasado mañana (el que se genera hoy). No incluye la demanda de ahora-a-mañana (ver "Demanda tramo 1"), que el stock proyectado actual debe aguantar solo.')
                    ->color(fn (DirectivaTransferenciaSugerencia $r): string => $r->esConfianzaBaja() ? 'gray' : 'success'),
                TextColumn::make('demanda_ventana1')->label('Demanda tramo 1')->numeric(2)->alignEnd()->toggleable(isToggledHiddenByDefault: true)
                    ->tooltip('Solo el primer tramo (ahora -> mañana) -- lo que el STOCK PROYECTADO ACTUAL tiene que aguantar por sí solo, sin ayuda del despacho de hoy (llega tarde para este tramo).'),
                TextColumn::make('demanda_promedio')->label('Demanda total (2 tramos)')->numeric(2)->alignEnd()->toggleable(isToggledHiddenByDefault: true)
                    ->tooltip('Ventana completa AHORA hasta PASADO MAÑANA (tramo 1 + tramo 2) -- el número que de verdad usa la fórmula de Cantidad sugerida por dentro (resta el stock proyectado UNA sola vez, arrastrando correctamente el sobrante/faltante del tramo 1 hacia el tramo 2). Se deja visible aparte, oculta por defecto, solo para quien quiera auditar el cálculo completo.'),
                TextColumn::make('riesgo_quiebre')->label('¿Riesgo de quiebre?')->badge()
                    ->formatStateUsing(fn ($state): string => $state ? 'Quiebre antes de mañana' : 'Sin riesgo')
                    ->color(fn ($state): string => $state ? 'danger' : 'gray')
                    ->tooltip('Si la demanda del tramo 1 (ahora -> mañana) ya supera el stock proyectado actual, el local se queda sin stock ANTES de que llegue la reposición de mañana -- el despacho de hoy no lo puede evitar, llega después de que ya pasó.'),
                TextColumn::make('semanas_consideradas')->label('Semanas')->alignEnd()->toggleable()
                    ->badge()->color(fn (DirectivaTransferenciaSugerencia $r): string => $r->esConfianzaBaja() ? 'warning' : 'gray'),
                TextColumn::make('saldo_actual')->label('Saldo actual')->numeric(2)->alignEnd()
                    ->color(fn ($state): string => (float) $state < 0 ? 'danger' : 'gray'),
                TextColumn::make('cantidad_en_transito')->label('En tránsito')->numeric(2)->alignEnd()->toggleable()
                    ->tooltip('Guías internas con fecha de traslado entre hoy y mañana (el tramo 1, ambas incluidas), todavía sin confirmar recepción -- ya sumadas al stock proyectado antes de calcular la sugerencia.')
                    ->color(fn ($state): string => (float) $state > 0 ? 'info' : 'gray'),
                TextColumn::make('multiplo_aplicado')->label('Múltiplo')->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('porcentaje_ajuste_aplicado')->label('Ajuste %')->alignEnd()->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn ($state): string => $state != 0 ? number_format((float) $state, 2).'%' : '--')
                    ->tooltip('% aplicado con el wizard "Iniciar Directiva de Transferencia" sobre la cantidad bruta de esta fila -- 0 = sin ajuste, fórmula normal.')
                    ->color(fn ($state): string => (float) $state != 0 ? 'warning' : 'gray'),
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
                Filter::make('riesgo_quiebre')->label('Riesgo de quiebre antes de mañana')
                    ->query(fn (Builder $query): Builder => $query->where('riesgo_quiebre', true)),
            ])
            ->defaultSort('fecha_despacho')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('Todavía no se calculó la Directiva de Transferencia de mañana -- usa "Recalcular para mañana" o "Sincronizar y calcular para mañana".');
    }
}
