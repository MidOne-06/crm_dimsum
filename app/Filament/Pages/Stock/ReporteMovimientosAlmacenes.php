<?php

namespace App\Filament\Pages\Stock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Models\MovimientoAlmacenDetalle;
use App\Models\MovimientoAlmacenHistorico;
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
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reporte de la copia histórica de movimientos. La extracción es la única
 * responsable de actualizar estos datos contra Restaurant; el reporte no
 * genera operaciones ni altera cabeceras o detalles.
 */
class ReporteMovimientosAlmacenes extends Page implements HasTable
{
    use InteractsWithTable;
    use ScopesLocalsToUser;

    private const ALL_LOCALES_OPTION = '__all_locales__';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-table-cells';
    protected static ?string $navigationLabel = 'Reporte de movimientos';
    protected static ?string $title = 'Reporte de movimientos entre almacenes';
    protected static string|\UnitEnum|null $navigationGroup = 'Movimientos entre almacenes';
    protected static ?int $navigationSort = 12;
    protected static ?string $slug = 'movimientos-almacenes/reporte';
    protected string $view = 'filament.pages.stock.reporte-movimientos-almacenes';

    /** @var array<string, string> */
    public array $localOptions = [];

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('movimientos-almacenes.reporte.view');
    }

    public function mount(): void
    {
        $this->localOptions = $this->historyLocalOptions();
        $latest = MovimientoAlmacenHistorico::query()->max('fecha');
        $anchor = $latest ? Carbon::parse($latest)->min(now()) : now();

        $this->form->fill([
            'metric' => 'cantidad',
            'estado' => '',
            'estadoRecepcion' => '',
            'codigo' => '',
            'origenLocals' => [self::ALL_LOCALES_OPTION],
            'destinoLocals' => [self::ALL_LOCALES_OPTION],
            'selectedProducts' => [],
            'dateStart' => $anchor->copy()->subDays(30)->toDateString(),
            'dateEnd' => $anchor->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'md' => 2, 'xl' => 4])->schema([
                Select::make('metric')
                    ->label('Cantidad a reportar')
                    ->options(['cantidad' => 'Cantidad', 'valorizado' => 'Valorizado (S/)'])
                    ->default('cantidad')->required()->native(false),
                Select::make('estado')->label('Estado')->options($this->statusOptions())->placeholder('Todos los estados')->native(),
                Select::make('estadoRecepcion')->label('Estado de recepción')->options($this->receiptStatusOptions())->placeholder('Todos')->native(),
                TextInput::make('codigo')->label('Código de movimiento')->maxLength(40),
                Select::make('origenLocals')->label('Local de origen')->options($this->localSelectOptions())
                    ->multiple()->searchable()->native(false)->optionsLimit(10)->placeholder('Todos los locales'),
                Select::make('destinoLocals')->label('Local de destino')->options($this->localSelectOptions())
                    ->multiple()->searchable()->native(false)->optionsLimit(10)->placeholder('Todos los locales'),
                Select::make('selectedProducts')->label('Productos')->multiple()->searchable()->native(false)->optionsLimit(20)
                    ->getSearchResultsUsing(fn (string $search): array => $this->productSearchResults($search))
                    ->getOptionLabelsUsing(fn (array $values): array => $this->productLabels($values))
                    ->placeholder('Todos los productos')->columnSpan(['xl' => 4]),
            ]),
        ])->statePath('data');
    }

    public function search(): void
    {
        $start = $this->dateStart();
        $end = $this->dateEnd();
        if ($start > $end) {
            [$start, $end] = [$end, $start];
            $this->data['dateStart'] = $start;
            $this->data['dateEnd'] = $end;
        }

        $this->resetPage();
        $this->resetTable();
        $this->cerrarFiltrosReporte();
    }

    public function abrirFiltrosReporte(): void
    {
        $this->dispatch('open-modal', id: 'filtros-reporte-movimientos-almacenes');
    }

    public function cerrarFiltrosReporte(): void
    {
        $this->dispatch('close-modal', id: 'filtros-reporte-movimientos-almacenes');
    }

    public function exportarExcel(): StreamedResponse
    {
        abort_unless(auth()->user()?->hasPermission('movimientos-almacenes.reporte.exportar'), 403);

        $locals = $this->matrixLocalNames();
        $rows = $this->matrixRows();
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Movimientos');

        $lastColumn = count($locals) + 4;
        $lastColumnLetter = Coordinate::stringFromColumnIndex($lastColumn);
        $headerRow = 5;
        $sheet->mergeCells("A1:{$lastColumnLetter}1");
        $sheet->setCellValue('A1', 'Reporte de movimientos entre almacenes');
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
            $format = $this->metricColumn() === 'valorizado' ? '#,##0.00' : '#,##0.####';
            $sheet->getStyle('D'.($headerRow + 1).":{$lastColumnLetter}{$lastRow}")->getNumberFormat()->setFormatCode($format);
            $sheet->getStyle("{$lastColumnLetter}".($headerRow + 1).":{$lastColumnLetter}{$lastRow}")->getFont()->setBold(true);
        }
        $sheet->getColumnDimension('A')->setWidth(15);
        $sheet->getColumnDimension('B')->setWidth(38);
        $sheet->getColumnDimension('C')->setWidth(14);
        foreach (range(4, $lastColumn) as $index) $sheet->getColumnDimensionByColumn($index)->setWidth(14);
        $sheet->freezePane('D'.($headerRow + 1));

        $writer = new Xlsx($spreadsheet);
        $filename = 'reporte-movimientos-almacenes-'.now()->format('Y-m-d_His').'.xlsx';

        return response()->streamDownload(function () use ($writer, $spreadsheet): void {
            try { $writer->save('php://output'); } finally { $spreadsheet->disconnectWorksheets(); }
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function exportarPdf(): StreamedResponse
    {
        abort_unless(auth()->user()?->hasPermission('movimientos-almacenes.reporte.exportar'), 403);
        $options = new DompdfOptions();
        $options->set('isRemoteEnabled', false);
        $pdf = new Dompdf($options);
        $pdf->setPaper('a3', 'landscape');
        $pdf->loadHtml(view('filament.pages.stock.reporte-movimientos-almacenes-pdf', [
            'locals' => $this->matrixLocalNames(), 'rows' => $this->matrixRows(), 'filters' => $this->exportFilterLabel(),
            'decimals' => $this->metricColumn() === 'valorizado' ? 2 : 4,
        ])->render());
        $pdf->render();
        $filename = 'reporte-movimientos-almacenes-'.now()->format('Y-m-d_His').'.pdf';

        return response()->streamDownload(fn () => print $pdf->output(), $filename, ['Content-Type' => 'application/pdf']);
    }

    public function table(Table $table): Table
    {
        return $table->query($this->matrixQuery())->heading('Matriz de movimientos entre almacenes')->columns([
            TextColumn::make('codigo')->label('Código')->searchable()->toggleable(),
            TextColumn::make('item')->label('Producto')->searchable()->wrap(),
            TextColumn::make('unidad')->label('Unidad')->badge(),
            ...$this->matrixLocalColumns(),
            TextColumn::make('cantidad_total')->label('Total')->state(fn (MovimientoAlmacenDetalle $record): string => $this->formatMetric((float) $record->cantidad_total))->alignEnd()->color('primary'),
        ])->defaultSort('cantidad_total', 'desc')->defaultKeySort(false)->paginated([10, 25, 50])->defaultPaginationPageOption(10)->emptyStateHeading('Sin registros extraídos para los filtros elegidos.');
    }

    protected function matrixQuery(): Builder
    {
        $metric = $this->metricColumn();
        $locals = $this->matrixLocalNames();
        $origins = $this->selectedLocalIds('origenLocals');
        $destinations = $this->selectedLocalIds('destinoLocals');
        $products = $this->selectedProducts();

        $query = MovimientoAlmacenDetalle::query()
            ->join('movimientos_almacenes_historico as movimientos', 'movimientos.id', '=', 'movimientos_almacenes_detalles.movimiento_id')
            ->whereDate('movimientos.fecha', '>=', $this->dateStart())
            ->whereDate('movimientos.fecha', '<=', $this->dateEnd())
            ->when($origins !== [], fn (Builder $query): Builder => $query->whereIn('movimientos.local_origen_id', $origins))
            ->when($destinations !== [], fn (Builder $query): Builder => $query->whereIn('movimientos.local_destino_id', $destinations))
            ->when(filled($this->data['estado'] ?? null), fn (Builder $query): Builder => $query->where('movimientos.estado_codigo', (string) $this->data['estado']))
            ->when(filled($this->data['estadoRecepcion'] ?? null), fn (Builder $query): Builder => $query->where('movimientos.estado_recepcion_codigo', (string) $this->data['estadoRecepcion']))
            ->when(filled($this->data['codigo'] ?? null), fn (Builder $query): Builder => $query->where('movimientos.restaurant_id', 'like', '%'.trim((string) $this->data['codigo']).'%'))
            ->when($products !== [], fn (Builder $query): Builder => $query->whereIn('movimientos_almacenes_detalles.codigo', $products))
            ->selectRaw("MIN(movimientos_almacenes_detalles.id) AS id, MAX(movimientos_almacenes_detalles.codigo) AS codigo, MAX(movimientos_almacenes_detalles.item) AS item, MAX(movimientos_almacenes_detalles.unidad) AS unidad, COALESCE(SUM(movimientos_almacenes_detalles.{$metric}), 0) AS cantidad_total")
            ->groupBy('movimientos_almacenes_detalles.codigo');

        foreach ($locals as $index => $localName) {
            $query->selectRaw("COALESCE(SUM(CASE WHEN movimientos.local_origen = ? THEN movimientos_almacenes_detalles.{$metric} ELSE 0 END), 0) AS local_{$index}", [$localName]);
        }

        return $query->orderByDesc('cantidad_total');
    }

    /** @return \Illuminate\Support\Collection<int, MovimientoAlmacenDetalle> */
    protected function matrixRows(): \Illuminate\Support\Collection { return $this->matrixQuery()->get(); }

    /** @return array<int, TextColumn> */
    protected function matrixLocalColumns(): array
    {
        return collect($this->matrixLocalNames())->map(function (string $name, int $index): TextColumn {
            $alias = "local_{$index}";
            return TextColumn::make($alias)->label($this->compactLocalLabel($name))
                ->state(fn (MovimientoAlmacenDetalle $record): string => $this->formatMetric((float) ($record->{$alias} ?? 0)))->alignEnd()->toggleable();
        })->all();
    }

    /** @return array<int, string> */
    protected function matrixLocalNames(): array
    {
        $selected = $this->selectedLocalNames('origenLocals');
        if ($selected !== []) return $selected;

        return $this->historyBaseQuery()->whereNotNull('local_origen')->distinct()->orderBy('local_origen')->pluck('local_origen')->all();
    }

    /** @return array<string, string> */
    protected function historyLocalOptions(): array
    {
        $origins = MovimientoAlmacenHistorico::query()->whereNotNull('local_origen_id')->whereNotNull('local_origen')
            ->selectRaw('local_origen_id, MAX(local_origen) AS local_origen')->groupBy('local_origen_id')->orderBy('local_origen')->pluck('local_origen', 'local_origen_id')->all();
        $destinations = MovimientoAlmacenHistorico::query()->whereNotNull('local_destino_id')->whereNotNull('local_destino')
            ->selectRaw('local_destino_id, MAX(local_destino) AS local_destino')->groupBy('local_destino_id')->orderBy('local_destino')->pluck('local_destino', 'local_destino_id')->all();
        $locals = $origins + $destinations;
        asort($locals, SORT_NATURAL | SORT_FLAG_CASE);

        return $this->scopeKeyedLocalsToUser($locals);
    }

    /** @return array<string, string> */
    protected function localSelectOptions(): array { return [self::ALL_LOCALES_OPTION => 'Todos los locales'] + $this->localOptions; }

    /** @return array<string, string> */
    public function productSearchResults(string $search): array
    {
        $search = trim($search);
        if (mb_strlen($search) < 2) return [];
        return MovimientoAlmacenDetalle::query()->where(fn (Builder $query): Builder => $query->where('codigo', 'ilike', "%{$search}%")->orWhere('item', 'ilike', "%{$search}%"))
            ->selectRaw('codigo, MAX(item) AS item')->groupBy('codigo')->orderBy('item')->limit(50)->get()
            ->mapWithKeys(fn (MovimientoAlmacenDetalle $item): array => [(string) $item->codigo => trim("{$item->codigo} · {$item->item}", ' ·')])->all();
    }

    /** @param array<int, mixed> $values @return array<string, string> */
    public function productLabels(array $values): array
    {
        return MovimientoAlmacenDetalle::query()->whereIn('codigo', $values)->selectRaw('codigo, MAX(item) AS item')->groupBy('codigo')->get()
            ->mapWithKeys(fn (MovimientoAlmacenDetalle $item): array => [(string) $item->codigo => trim("{$item->codigo} · {$item->item}", ' ·')])->all();
    }

    /** @return array<string, string> */
    protected function statusOptions(): array
    {
        return $this->historyBaseQuery()->whereNotNull('estado_codigo')->selectRaw('estado_codigo, MAX(estado) AS estado')->groupBy('estado_codigo')->orderBy('estado_codigo')->get()
            ->mapWithKeys(fn (MovimientoAlmacenHistorico $row): array => [(string) $row->estado_codigo => (string) ($row->estado ?: $row->estado_codigo)])->all();
    }

    /** @return array<string, string> */
    protected function receiptStatusOptions(): array
    {
        return $this->historyBaseQuery()->whereNotNull('estado_recepcion_codigo')->selectRaw('estado_recepcion_codigo, MAX(estado_recepcion) AS estado_recepcion')->groupBy('estado_recepcion_codigo')->orderBy('estado_recepcion_codigo')->get()
            ->mapWithKeys(fn (MovimientoAlmacenHistorico $row): array => [(string) $row->estado_recepcion_codigo => (string) ($row->estado_recepcion ?: $row->estado_recepcion_codigo)])->all();
    }

    /** @return array<int, string> */
    protected function selectedProducts(): array { return array_values(array_filter((array) ($this->data['selectedProducts'] ?? []), fn ($value): bool => filled($value))); }

    /** @return array<int, string> */
    protected function selectedLocalIds(string $field): array
    {
        $selected = array_values(array_filter((array) ($this->data[$field] ?? []), fn ($value): bool => filled($value)));
        if (in_array(self::ALL_LOCALES_OPTION, $selected, true)) {
            return auth()->user()?->isRestrictedToLocals() ? auth()->user()->assignedLocalIds() : array_keys($this->localOptions);
        }

        return $this->restrictLocalIdsToUser($selected);
    }

    /** @return array<int, string> */
    protected function selectedLocalNames(string $field): array
    {
        $ids = $this->selectedLocalIds($field);
        return array_values(array_intersect_key($this->localOptions, array_flip(array_map('strval', $ids))));
    }

    protected function historyBaseQuery(): Builder
    {
        $query = MovimientoAlmacenHistorico::query();
        if (auth()->user()?->isRestrictedToLocals()) {
            $query->whereIn('local_origen_id', auth()->user()->assignedLocalIds());
        }

        return $query;
    }

    protected function metricColumn(): string { return ($this->data['metric'] ?? 'cantidad') === 'valorizado' ? 'valorizado' : 'cantidad'; }
    protected function dateStart(): string { return Carbon::parse($this->data['dateStart'] ?? now()->startOfMonth())->toDateString(); }
    protected function dateEnd(): string { return Carbon::parse($this->data['dateEnd'] ?? now())->toDateString(); }
    protected function compactLocalLabel(string $name): string { return str_replace('DIM SUM ', '', $name); }
    protected function formatMetric(float $value): string { return number_format($value, $this->metricColumn() === 'valorizado' ? 2 : 4); }

    protected function exportFilterLabel(): string
    {
        $metric = $this->metricColumn() === 'valorizado' ? 'Valorizado (S/)' : 'Cantidad';
        $origin = $this->selectedLocalNames('origenLocals');
        $destination = $this->selectedLocalNames('destinoLocals');
        $all = array_values($this->localOptions);
        $originLabel = $origin === $all ? 'Todos' : implode(', ', $origin);
        $destinationLabel = $destination === $all ? 'Todos' : implode(', ', $destination);

        return "Fecha: {$this->dateStart()} al {$this->dateEnd()} | Métrica: {$metric} | Origen: {$originLabel} | Destino: {$destinationLabel}";
    }
}
