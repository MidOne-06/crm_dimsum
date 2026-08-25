<?php

namespace App\Filament\Pages\Stock;

use App\Filament\Concerns\InteractsWithStockFilters;
use Filament\Pages\Page;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockConsolidado extends Page
{
    use InteractsWithStockFilters;

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
    }

    /** @return array{rows: array<int, array<string, mixed>>, page: int, pages: int, total: int} */
    public function summaryPage(): array
    {
        $summary = $this->consolidateByLocalItem($this->filteredReportMaster());

        return $this->paginate($summary, $this->reportPage);
    }

    /**
     * Exporta a Excel el mismo consolidado que se ve en pantalla. Respeta los
     * filtros principales y los filtros de resultados activos, con una fila por
     * ítem/unidad y una columna por local, más una columna TOTAL.
     */
    public function exportarExcel(): StreamedResponse
    {
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
