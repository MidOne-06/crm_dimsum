<?php

namespace App\Filament\Pages\Stock;

use App\Filament\Concerns\InteractsWithStockFilters;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockConsolidado extends Page implements HasTable
{
    use InteractsWithStockFilters;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationLabel = 'Consolidado';

    protected static ?string $title = 'Stock Consolidado';

    protected static string|\UnitEnum|null $navigationGroup = 'Stock Actual';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.stock.consolidado';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('stock.consolidado.view');
    }

    public function search(): void
    {
        $this->loadStockReport();
        $this->resetTable();
    }

    protected function getHeaderActions(): array
    {
        return [$this->filtrosModalAction()];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (int $page, int $recordsPerPage): LengthAwarePaginator => $this->tableRecords($page, $recordsPerPage))
            ->columns([
                TextColumn::make('itemCodigo')->label('Código')->searchable()->toggleable(),
                TextColumn::make('local')->label('Local')->searchable()->sortable()->wrap(),
                TextColumn::make('item')->label('Ítem')->searchable()->sortable()->wrap(),
                TextColumn::make('almacenes')->label('Almacenes')->numeric()->alignEnd()->sortable(),
                TextColumn::make('stockActual')->label('Stock consolidado')->numeric(3)->alignEnd()->sortable()
                    ->suffix(fn (array $record): string => filled($record['unidad'] ?? null) ? ' '.$record['unidad'] : ''),
            ])
            ->filters([
                Filter::make('resultados')
                    ->label('Filtros')
                    ->schema([
                        Select::make('local')->label('Local')->native(false)->searchable()->options(fn (): array => $this->resultFilterOptions('local'))->placeholder('Todos los locales'),
                        Select::make('almacen')->label('Almacén')->native(false)->searchable()->options(fn (): array => $this->resultFilterOptions('almacen'))->placeholder('Todos los almacenes'),
                        Select::make('item')->label('Ítem')->native(false)->searchable()->options(fn (): array => $this->resultFilterOptions('item'))->placeholder('Todos los ítems'),
                        Select::make('tipo')->label('Tipo')->native(false)->searchable()->options(fn (): array => $this->resultFilterOptions('tipo'))->placeholder('Todos los tipos'),
                    ]),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(['default' => 1, 'md' => 2, 'xl' => 4])
            ->headerActions([
                Action::make('exportarExcel')
                    ->label('Exportar Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->visible(fn (): bool => (bool) auth()->user()?->hasPermission('stock.consolidado.exportar'))
                    ->action(fn (): StreamedResponse => $this->exportarExcel()),
            ])
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('Sin stock para los filtros seleccionados.');
    }

    /** @return array<string, string> */
    public function resultFilterOptions(string $field): array
    {
        return collect($this->uniqueReportValues($field))
            ->mapWithKeys(fn (string $value): array => [$value => $value])
            ->all();
    }

    protected function tableRecords(int $page, int $recordsPerPage): LengthAwarePaginator
    {
        $rows = collect($this->consolidateByLocalItem($this->filteredReportMaster()));

        return new LengthAwarePaginator(
            $rows->forPage($page, $recordsPerPage)->values(),
            $rows->count(),
            $recordsPerPage,
            $page,
            ['path' => request()->url(), 'pageName' => 'stockConsolidadoPage'],
        );
    }

    /**
     * Exporta a Excel el mismo consolidado que se ve en pantalla. Respeta los
     * filtros principales y los filtros de resultados activos, con una fila por
     * ítem/unidad y una columna por local, más una columna TOTAL.
     */
    public function exportarExcel(): StreamedResponse
    {
        abort_unless(auth()->user()?->hasPermission('stock.consolidado.exportar'), 403);
        $consolidado = $this->consolidateByLocalItem($this->filteredReportMaster());

        $locales = collect($consolidado)->pluck('local')->unique()->sort(SORT_STRING | SORT_FLAG_CASE)->values();

        $items = [];
        foreach ($consolidado as $row) {
            // La descripción no es una llave fiable: el mismo texto puede existir
            // para distintos ítems o unidades. Se conserva la identidad del origen.
            $itemKey = ($row['itemId'] ?? '').'|'.($row['unidad'] ?? '').'|'.($row['item'] ?? '');
            $items[$itemKey] ??= [
                'id' => $row['itemId'] ?? '',
                'codigo' => $row['itemCodigo'] ?? '',
                'item' => $row['item'] ?? '',
                'unidad' => $row['unidad'] ?? '',
                'porLocal' => [],
            ];
            $items[$itemKey]['porLocal'][$row['local']] = ($items[$itemKey]['porLocal'][$row['local']] ?? 0) + $row['stockActual'];
        }
        uasort($items, static fn (array $a, array $b): int => [$a['item'], $a['unidad'], $a['id']] <=> [$b['item'], $b['unidad'], $b['id']]);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Stock Consolidado');

        // A=Código, B=Ítem, C=Unidad, luego un local por columna y TOTAL.
        $lastCol = $locales->count() + 4;
        $lastColLetter = Coordinate::stringFromColumnIndex($lastCol);
        $headerRow = 6;

        $sheet->mergeCells('A1:'.$lastColLetter.'1');
        $sheet->setCellValue('A1', 'Stock consolidado');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->mergeCells('A2:'.$lastColLetter.'2');
        $sheet->setCellValue('A2', 'Rango: '.$this->exportDateRange().' | Generado: '.now()->format('d/m/Y H:i:s'));
        $sheet->mergeCells('A3:'.$lastColLetter.'3');
        $sheet->setCellValue('A3', 'Cuadres incluidos: '.number_format($this->cuadresIncluidos).' de '.number_format($this->cuadresEncontrados).' | Páginas consultadas: '.number_format($this->paginasConsultadas));
        $sheet->mergeCells('A4:'.$lastColLetter.'4');
        $sheet->setCellValue('A4', 'Filtros de resultados: '.$this->exportResultFilters());

        $sheet->setCellValue([1, $headerRow], 'Código');
        $sheet->setCellValue([2, $headerRow], 'Ítem');
        $sheet->setCellValue([3, $headerRow], 'Unidad');
        foreach ($locales as $index => $local) {
            $sheet->setCellValue([$index + 4, $headerRow], $local);
        }
        $sheet->setCellValue([$lastCol, $headerRow], 'TOTAL');

        $headerRange = 'A'.$headerRow.':'.$lastColLetter.$headerRow;
        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5E7EB');
        $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $rowNumber = $headerRow + 1;
        foreach ($items as $data) {
            $sheet->setCellValue([1, $rowNumber], $data['codigo'] ?: $data['id']);
            $sheet->setCellValue([2, $rowNumber], $data['item']);
            $sheet->setCellValue([3, $rowNumber], $data['unidad']);

            $total = 0.0;
            foreach ($locales as $index => $local) {
                $cantidad = $data['porLocal'][$local] ?? 0.0;
                $sheet->setCellValue([$index + 4, $rowNumber], $cantidad != 0 ? $cantidad : null);
                $total += $cantidad;
            }
            $sheet->setCellValue([$lastCol, $rowNumber], $total);
            $rowNumber++;
        }

        $lastRow = $rowNumber - 1;
        if ($lastRow > $headerRow) {
            $sheet->getStyle($lastColLetter.($headerRow + 1).':'.$lastColLetter.$lastRow)->getFont()->setBold(true);
            $sheet->getStyle('D'.($headerRow + 1).':'.$lastColLetter.$lastRow)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);

            $fullRange = 'A'.$headerRow.':'.$lastColLetter.$lastRow;
            $sheet->getStyle($fullRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFD1D5DB'));
        }

        // Anchos fijos: autoSize recorre todas las celdas y escala mal en exportaciones grandes.
        $sheet->getColumnDimension('A')->setWidth(16);
        $sheet->getColumnDimension('B')->setWidth(42);
        $sheet->getColumnDimension('C')->setWidth(14);
        foreach (range(4, $lastCol) as $colIndex) {
            $sheet->getColumnDimensionByColumn($colIndex)->setWidth(16);
        }
        $sheet->freezePane('D'.($headerRow + 1));

        $writer = new Xlsx($spreadsheet);
        $filename = 'stock-consolidado-'.now()->format('Y-m-d_His').'.xlsx';

        return response()->streamDownload(function () use ($writer, $spreadsheet) {
            try {
                $writer->save('php://output');
            } finally {
                $spreadsheet->disconnectWorksheets();
            }
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function exportDateRange(): string
    {
        return ($this->data['fechaInicio'] ?? '—').' al '.($this->data['fechaFin'] ?? '—');
    }

    private function exportResultFilters(): string
    {
        return implode(' | ', [
            'Local: '.($this->reportFilterLocal ?: 'Todos'),
            'Almacén: '.($this->reportFilterAlmacen ?: 'Todos'),
            'Ítem: '.($this->reportFilterItem ?: 'Todos'),
            'Tipo: '.($this->reportFilterTipo ?: 'Todos'),
        ]);
    }
}
