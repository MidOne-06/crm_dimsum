<?php

namespace App\Filament\Pages\RequerimientosStock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Models\RequerimientoStockHistorico;
use App\Models\RequerimientoStockHistoricoDetalle;
use App\Models\RequerimientoStockSincronizacion;
use App\Services\RequerimientoStockGatewayClient;
use App\Services\RequerimientoStockHistoricoService;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options as DompdfOptions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Process;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ReporteRequerimientos extends Page implements HasTable
{
    use InteractsWithTable;
    use ScopesLocalsToUser;

    private const ALL_LOCALES_OPTION = '__all_locales__';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-table-cells';
    protected static ?string $navigationLabel = 'Reporte de requerimientos';
    protected static ?string $title = 'Reporte de requerimientos';
    protected static string|\UnitEnum|null $navigationGroup = 'Requerimientos de Stock';
    protected static ?int $navigationSort = 7;
    protected static ?string $slug = 'requerimientos-stock/reporte';
    protected string $view = 'filament.pages.requerimientos-stock.reporte';

    /** @var array<string, string> */
    public array $localOptions = [];

    /** @var array<string, mixed> */
    public array $data = [];

    public string $activeDatePreset = 'month';

    public ?int $sincronizacionReporteId = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('requerimientos-stock.reporte.view');
    }

    public function mount(): void
    {
        try {
            $this->localOptions = collect($this->scopeLocalsToUser($this->gateway()->locals()))
                ->mapWithKeys(fn (array $local): array => [(string) $local['id'] => (string) $local['name']])
                ->all();
        } catch (Throwable) {
            // El reporte sigue disponible para consultar el historial local.
            $this->localOptions = $this->localOptionsFromHistory();
        }

        $this->form->fill([
            'fechaTipo' => 'registro',
            'estado' => '',
            'metric' => 'solicitada',
            'selectedLocals' => [],
            'selectedProducts' => [],
        ]);
        // "Mes en curso" como default hacía que el reporte abriera vacío en
        // cuanto no hubiera datos sincronizados para el día de hoy (este
        // módulo no tiene sincronización automática -- depende de que un
        // usuario presione "Sincronizar filtro" a mano). Se usa la fecha más
        // reciente que SÍ tenemos guardada como ancla, con 30 días hacia
        // atrás, para que el primer vistazo casi siempre muestre algo real
        // en vez de un "Sin registros" engañoso.
        $ultimaFechaConDatos = RequerimientoStockHistorico::query()->max('fecha_registro');
        $anclaFin = $ultimaFechaConDatos ? Carbon::parse($ultimaFechaConDatos)->min(now()) : now();
        $this->data['dateStart'] = $anclaFin->copy()->subDays(30)->toDateString();
        $this->data['dateEnd'] = $anclaFin->toDateString();
        $this->sincronizacionReporteId = RequerimientoStockSincronizacion::query()->latest('id')->value('id');

        $this->autoSincronizar();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'md' => 2, 'xl' => 4])->schema([
                Select::make('fechaTipo')
                    ->label('Filtrar fecha por')
                    ->options(['registro' => 'Fecha de registro', 'abastecimiento' => 'Fecha de abastecimiento'])
                    ->default('registro')->required()->native(false),
                Select::make('metric')
                    ->label('Cantidad a reportar')
                    ->options(['solicitada' => 'Solicitada', 'despachada' => 'Despachada', 'preparada' => 'Preparada'])
                    ->default('solicitada')->required()->native(false),
                Select::make('estado')
                    ->label('Estado')
                    ->options($this->statusOptions())
                    ->placeholder('Todos los estados')->native(false)->searchable(),
                TextInput::make('codigo')
                    ->label('Código de requerimiento')
                    ->maxLength(40),
                Select::make('selectedLocals')
                    ->label('Locales de la matriz')
                    ->options($this->localSelectOptions())
                    ->multiple()->searchable()->native(false)->optionsLimit(10)
                    ->placeholder('Todos los locales'),
                Select::make('selectedProducts')
                    ->label('Productos')
                    ->multiple()->searchable()->native(false)->optionsLimit(20)
                    ->getSearchResultsUsing(fn (string $search): array => $this->productSearchResults($search))
                    ->getOptionLabelsUsing(fn (array $values): array => $this->productLabels($values))
                    ->placeholder('Todos los productos')
                    ->columnSpan(['xl' => 2]),
            ]),
        ])->statePath('data');
    }

    public function syncDateRange(string $start, string $end, string $preset = 'custom'): void
    {
        $start = Carbon::parse($start)->toDateString();
        $end = Carbon::parse($end)->toDateString();
        if ($start > $end) [$start, $end] = [$end, $start];

        $this->data['dateStart'] = $start;
        $this->data['dateEnd'] = $end;
        $this->activeDatePreset = $preset;
    }

    public function search(): void
    {
        $this->resetPage();
        $this->autoSincronizar();
    }

    /** Ancla para que el usuario entienda por qué un rango reciente puede salir vacío: este módulo no se sincroniza solo. */
    public function ultimaFechaConDatos(): ?string
    {
        $fecha = RequerimientoStockHistorico::query()->max('fecha_registro');

        return $fecha ? Carbon::parse($fecha)->format('d/m/Y H:i') : null;
    }

    /**
     * Sincroniza el filtro actual contra Restaurant de forma implícita --
     * cada vez que se abre o se consulta el reporte, no por un botón manual.
     * Antes cada clic en "Sincronizar filtro" podía apilar corridas
     * concurrentes (y con ellas, filas de sincronización y barras de
     * progreso confusas en pantalla) si varios usuarios lo presionaban a la
     * vez o el mismo usuario reaplicaba filtros varias veces seguidas. Dos
     * guardas lo resuelven sin exponer nada al usuario:
     *  - si ya hay una corrida pendiente/en_progreso (de cualquier filtro),
     *    no se lanza otra -- solo una corriendo a la vez para todo el módulo.
     *  - si el MISMO filtro ya se sincronizó hace menos de 5 minutos, se
     *    reutiliza ese resultado en vez de volver a pedirle lo mismo a
     *    Restaurant (evita machacar el ERP si el usuario solo está
     *    reordenando/paginando la tabla).
     */
    private function autoSincronizar(): void
    {
        if (! auth()->user()?->hasPermission('requerimientos-stock.reporte.sincronizar')) {
            return;
        }

        if (RequerimientoStockSincronizacion::query()->whereIn('estado', ['pendiente', 'en_progreso'])->exists()) {
            return;
        }

        // Comparación en PHP, no en SQL: el cast a jsonb no garantiza el
        // mismo orden de claves que json_encode() al comparar como texto, y
        // el volumen aquí (últimas corridas completadas) es mínimo.
        $filtros = $this->remoteFilters();
        $reciente = RequerimientoStockSincronizacion::query()
            ->whereIn('estado', ['completado', 'completado_con_errores'])
            ->where('completado_en', '>=', now()->subMinutes(5))
            ->latest('id')->limit(10)->get(['filtros'])
            ->contains(fn (RequerimientoStockSincronizacion $run): bool => $run->filtros == $filtros);
        if ($reciente) {
            return;
        }

        $run = RequerimientoStockSincronizacion::create([
            'filtros' => $filtros,
            'estado' => 'pendiente',
            'iniciado_por' => auth()->id(),
        ]);
        Process::path(base_path())->start(['php', 'artisan', 'requerimientos-stock:sincronizar-reporte', '--sync-id='.$run->id]);
        $this->sincronizacionReporteId = $run->id;
    }

    public function sincronizacionReporteActual(): ?RequerimientoStockSincronizacion
    {
        return $this->sincronizacionReporteId ? RequerimientoStockSincronizacion::find($this->sincronizacionReporteId) : null;
    }

    public function refreshSincronizacionReporte(): void
    {
        $run = $this->sincronizacionReporteActual();
        if ($run && ! in_array($run->estado, ['pendiente', 'en_progreso'], true)) {
            $this->resetPage();
        }
    }

    public function exportarExcel(): StreamedResponse
    {
        abort_unless(auth()->user()?->hasPermission('requerimientos-stock.reporte.exportar'), 403);
        $locals = $this->matrixLocalNames();
        $rows = $this->matrixRows();
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Requerimientos');

        $lastColumn = count($locals) + 4;
        $lastColumnLetter = Coordinate::stringFromColumnIndex($lastColumn);
        $headerRow = 5;

        $sheet->mergeCells("A1:{$lastColumnLetter}1");
        $sheet->setCellValue('A1', 'Reporte de requerimientos de stock');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->mergeCells("A2:{$lastColumnLetter}2");
        $sheet->setCellValue('A2', $this->exportFilterLabel());

        foreach (['Código', 'Producto', 'Unidad'] as $index => $heading) {
            $sheet->setCellValue([$index + 1, $headerRow], $heading);
        }
        foreach ($locals as $index => $local) {
            $sheet->setCellValue([$index + 4, $headerRow], $local);
        }
        $sheet->setCellValue([$lastColumn, $headerRow], 'TOTAL');

        $headerRange = "A{$headerRow}:{$lastColumnLetter}{$headerRow}";
        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5E7EB');
        $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        foreach (range(4, $lastColumn) as $columnIndex) {
            $sheet->getStyle(Coordinate::stringFromColumnIndex($columnIndex).$headerRow)->getAlignment()->setTextRotation(90);
        }
        $sheet->getRowDimension($headerRow)->setRowHeight(120);

        $rowNumber = $headerRow + 1;
        foreach ($rows as $row) {
            $sheet->setCellValue([1, $rowNumber], $row->codigo);
            $sheet->setCellValue([2, $rowNumber], $row->item);
            $sheet->setCellValue([3, $rowNumber], $row->unidad);
            foreach ($locals as $index => $_) {
                $sheet->setCellValue([$index + 4, $rowNumber], (float) ($row->{'local_'.$index} ?? 0));
            }
            $sheet->setCellValue([$lastColumn, $rowNumber], (float) $row->cantidad_total);
            $rowNumber++;
        }

        $lastRow = max($headerRow, $rowNumber - 1);
        $sheet->getStyle("A{$headerRow}:{$lastColumnLetter}{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFD1D5DB'));
        if ($lastRow > $headerRow) {
            $sheet->getStyle('D'.($headerRow + 1).":{$lastColumnLetter}{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle("{$lastColumnLetter}".($headerRow + 1).":{$lastColumnLetter}{$lastRow}")->getFont()->setBold(true);
        }
        $sheet->getColumnDimension('A')->setWidth(15);
        $sheet->getColumnDimension('B')->setWidth(38);
        $sheet->getColumnDimension('C')->setWidth(14);
        foreach (range(4, $lastColumn) as $index) $sheet->getColumnDimensionByColumn($index)->setWidth(14);
        $sheet->freezePane('D'.($headerRow + 1));

        $writer = new Xlsx($spreadsheet);
        $filename = 'reporte-requerimientos-'.now()->format('Y-m-d_His').'.xlsx';

        return response()->streamDownload(function () use ($writer, $spreadsheet): void {
            try {
                $writer->save('php://output');
            } finally {
                $spreadsheet->disconnectWorksheets();
            }
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function exportarPdf(): StreamedResponse
    {
        abort_unless(auth()->user()?->hasPermission('requerimientos-stock.reporte.exportar'), 403);
        $options = new DompdfOptions();
        $options->set('isRemoteEnabled', false);
        $pdf = new Dompdf($options);
        $pdf->setPaper('a3', 'landscape');
        $pdf->loadHtml(view('filament.pages.requerimientos-stock.reporte-pdf', [
            'locals' => $this->matrixLocalNames(),
            'rows' => $this->matrixRows(),
            'filters' => $this->exportFilterLabel(),
        ])->render());
        $pdf->render();

        $filename = 'reporte-requerimientos-'.now()->format('Y-m-d_His').'.pdf';

        // Un Response binario devuelto directo desde una acción wire:click
        // hace que Livewire intente serializar el PDF completo como parte
        // del payload JSON de la respuesta AJAX -- y json_encode truena con
        // "Malformed UTF-8 characters" al toparse con bytes binarios (el PDF
        // no es texto). streamDownload() es la forma que Livewire reconoce
        // como descarga de archivo y NO intenta meter en el JSON; es el
        // mismo patrón que ya usa exportarExcel() más abajo, que sí funciona.
        return response()->streamDownload(function () use ($pdf): void {
            echo $pdf->output();
        }, $filename, ['Content-Type' => 'application/pdf']);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->matrixQuery())
            ->heading('Matriz de requerimientos')
            ->columns([
                TextColumn::make('codigo')->label('Código')->searchable()->toggleable(),
                TextColumn::make('item')->label('Producto')->searchable()->wrap(),
                TextColumn::make('unidad')->label('Unidad')->badge(),
                ...$this->matrixLocalColumns(),
                TextColumn::make('cantidad_total')->label('Total')->state(fn (RequerimientoStockHistoricoDetalle $record): string => number_format((float) $record->cantidad_total, 0))->alignEnd()->color('primary'),
            ])
            ->defaultSort('cantidad_total', 'desc')
            ->defaultKeySort(false)
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('Sin registros');
    }

    protected function matrixQuery(): Builder
    {
        $metric = $this->metricColumn();
        $dateColumn = ($this->data['fechaTipo'] ?? 'registro') === 'abastecimiento' ? 'fecha_abastecimiento' : 'fecha_registro';
        $locals = $this->matrixLocalNames();
        $products = $this->selectedProducts();

        $query = RequerimientoStockHistoricoDetalle::query()
            ->join('requerimientos_stock_historicos as requerimientos', 'requerimientos.id', '=', 'requerimientos_stock_historicos_detalles.requerimiento_stock_historico_id')
            ->where('requerimientos_stock_historicos_detalles.activo', true)
            ->whereDate("requerimientos.{$dateColumn}", '>=', $this->dateStart())
            ->whereDate("requerimientos.{$dateColumn}", '<=', $this->dateEnd())
            ->when(filled($this->data['estado'] ?? null), fn (Builder $query): Builder => $query->where('requerimientos.estado', $this->data['estado']))
            ->when(filled($this->data['codigo'] ?? null), fn (Builder $query): Builder => $query->where('requerimientos.erp_id', (string) $this->data['codigo']))
            ->when($products !== [], fn (Builder $query): Builder => $query->whereIn('requerimientos_stock_historicos_detalles.codigo', $products))
            ->selectRaw("MIN(requerimientos_stock_historicos_detalles.id) AS id, MAX(requerimientos_stock_historicos_detalles.codigo) AS codigo, MAX(requerimientos_stock_historicos_detalles.item) AS item, MAX(requerimientos_stock_historicos_detalles.unidad) AS unidad, COALESCE(SUM(requerimientos_stock_historicos_detalles.{$metric}), 0) AS cantidad_total")
            ->groupBy('requerimientos_stock_historicos_detalles.codigo');

        foreach ($locals as $index => $localName) {
            $query->selectRaw("COALESCE(SUM(CASE WHEN requerimientos.solicitado_por = ? THEN requerimientos_stock_historicos_detalles.{$metric} ELSE 0 END), 0) AS local_{$index}", [$localName]);
        }

        return $query->orderByDesc('cantidad_total');
    }

    /** @return \Illuminate\Support\Collection<int, RequerimientoStockHistoricoDetalle> */
    protected function matrixRows(): \Illuminate\Support\Collection
    {
        return $this->matrixQuery()->get();
    }

    /** @return array<int, TextColumn> */
    protected function matrixLocalColumns(): array
    {
        return collect($this->matrixLocalNames())->map(function (string $name, int $index): TextColumn {
            $alias = "local_{$index}";
            return TextColumn::make($alias)
                ->label($this->compactLocalLabel($name))
                ->state(fn (RequerimientoStockHistoricoDetalle $record): string => number_format((float) ($record->{$alias} ?? 0), 0))
                ->alignEnd()
                ->toggleable();
        })->all();
    }

    /** @return array<int, string> */
    protected function matrixLocalNames(): array
    {
        $selected = (array) ($this->data['selectedLocals'] ?? []);
        if (in_array(self::ALL_LOCALES_OPTION, $selected, true)) return array_values($this->localOptions);

        $ids = $this->restrictLocalIdsToUser(array_values(array_filter($selected, fn ($value): bool => filled($value))));
        if ($ids !== []) return array_values(array_intersect_key($this->localOptions, array_flip(array_map('strval', $ids))));

        return RequerimientoStockHistorico::query()
            ->whereDate($this->dateColumn(), '>=', $this->dateStart())
            ->whereDate($this->dateColumn(), '<=', $this->dateEnd())
            ->whereNotNull('solicitado_por')->distinct()->orderBy('solicitado_por')->pluck('solicitado_por')->all();
    }

    /** @return array<string, string> */
    protected function localSelectOptions(): array
    {
        return [self::ALL_LOCALES_OPTION => 'Todos los locales'] + $this->localOptions;
    }

    /** @return array<string, string> */
    public function productSearchResults(string $search): array
    {
        $search = trim($search);
        if (mb_strlen($search) < 2) return [];

        return RequerimientoStockHistoricoDetalle::query()
            ->where('activo', true)
            ->where(fn (Builder $query): Builder => $query->where('codigo', 'ilike', "%{$search}%")->orWhere('item', 'ilike', "%{$search}%"))
            ->selectRaw('codigo, MAX(item) AS item')->groupBy('codigo')->orderBy('item')->limit(50)->get()
            ->mapWithKeys(fn (RequerimientoStockHistoricoDetalle $item): array => [(string) $item->codigo => trim("{$item->codigo} · {$item->item}", ' ·')])->all();
    }

    /** @param array<int, mixed> $values @return array<string, string> */
    public function productLabels(array $values): array
    {
        return RequerimientoStockHistoricoDetalle::query()->whereIn('codigo', $values)->where('activo', true)
            ->selectRaw('codigo, MAX(item) AS item')->groupBy('codigo')->get()
            ->mapWithKeys(fn (RequerimientoStockHistoricoDetalle $item): array => [(string) $item->codigo => trim("{$item->codigo} · {$item->item}", ' ·')])->all();
    }

    /** @return array<string, string> */
    protected function statusOptions(): array
    {
        return RequerimientoStockHistorico::query()->whereNotNull('estado')->distinct()->orderBy('estado')->pluck('estado', 'estado')->all();
    }

    /** @return array<string, string> */
    protected function localOptionsFromHistory(): array
    {
        return RequerimientoStockHistorico::query()->whereNotNull('solicitado_por')->distinct()->orderBy('solicitado_por')->pluck('solicitado_por', 'solicitado_por')->all();
    }

    /** @return array<int, string> */
    protected function selectedProducts(): array
    {
        return array_values(array_filter((array) ($this->data['selectedProducts'] ?? []), fn ($value): bool => filled($value)));
    }

    protected function metricColumn(): string
    {
        return match ($this->data['metric'] ?? 'solicitada') {
            'despachada' => 'cantidad_despachada', 'preparada' => 'cantidad_preparada', default => 'cantidad_solicitada',
        };
    }

    protected function dateColumn(): string
    {
        return ($this->data['fechaTipo'] ?? 'registro') === 'abastecimiento' ? 'fecha_abastecimiento' : 'fecha_registro';
    }

    protected function dateStart(): string { return Carbon::parse($this->data['dateStart'] ?? now()->startOfMonth())->toDateString(); }
    protected function dateEnd(): string { return Carbon::parse($this->data['dateEnd'] ?? now())->toDateString(); }
    protected function compactLocalLabel(string $name): string { return str_replace('DIM SUM ', '', $name); }

    protected function exportFilterLabel(): string
    {
        $dateLabel = ($this->data['fechaTipo'] ?? 'registro') === 'abastecimiento' ? 'Abastecimiento' : 'Registro';
        $metric = ['solicitada' => 'Solicitada', 'despachada' => 'Despachada', 'preparada' => 'Preparada'][$this->data['metric'] ?? 'solicitada'];

        return "Fecha de {$dateLabel}: {$this->dateStart()} al {$this->dateEnd()} | Cantidad: {$metric}";
    }

    /** @return array<string, mixed> */
    protected function remoteFilters(): array
    {
        $selected = (array) ($this->data['selectedLocals'] ?? []);
        $locals = in_array(self::ALL_LOCALES_OPTION, $selected, true)
            ? array_keys($this->localOptions)
            : $this->restrictLocalIdsToUser(array_values(array_filter($selected, fn ($value): bool => filled($value))));

        return [
            'fecha_inicio' => $this->dateStart(), 'fecha_fin' => $this->dateEnd(),
            'locales' => $locals, 'locales_produccion' => [],
            'estado' => '-1', 'codigo' => trim((string) ($this->data['codigo'] ?? '')),
            'encargado' => '', 'por_fecha' => ($this->data['fechaTipo'] ?? 'registro') === 'abastecimiento' ? '1' : '0', 'items' => [],
        ];
    }

    private function gateway(): RequerimientoStockGatewayClient { return app(RequerimientoStockGatewayClient::class); }
}
