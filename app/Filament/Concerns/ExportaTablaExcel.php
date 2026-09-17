<?php

namespace App\Filament\Concerns;

use Filament\Actions\Action;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Export genérico a Excel para tablas Filament de solo lectura -- pedido
 * explícito del usuario (2026-09-17): Historial de tandas, Historial de
 * salidas y Consolidado no tenían forma de descargar lo que se ve en
 * pantalla, a diferencia de la Directiva de Transferencia que sí exporta.
 * Usa getTableQueryForExport() (nativo de Filament, respeta filtros,
 * búsqueda y orden ya aplicados en pantalla) -- así el Excel que baja el
 * usuario es exactamente lo que está viendo, no todo el histórico.
 */
trait ExportaTablaExcel
{
    /**
     * @param  array<int, string>  $encabezados
     * @param  \Closure(mixed): array<int, mixed>  $filaMapper  Recibe cada registro (ya con relaciones cargadas por la query de la tabla) y devuelve la fila de celdas.
     */
    protected function exportarExcelAction(string $nombreArchivo, array $encabezados, \Closure $filaMapper): Action
    {
        return Action::make('exportarExcel')
            ->label('Exportar Excel')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->action(function () use ($nombreArchivo, $encabezados, $filaMapper) {
                $registros = $this->getTableQueryForExport()->get();

                $spreadsheet = new Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();
                foreach ($encabezados as $col => $titulo) {
                    $sheet->setCellValue([$col + 1, 1], $titulo);
                }
                $ultimaColumna = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($encabezados));
                $sheet->getStyle("A1:{$ultimaColumna}1")->getFont()->setBold(true);
                $sheet->getStyle("A1:{$ultimaColumna}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DCE6F1');

                $fila = 2;
                foreach ($registros as $registro) {
                    foreach (array_values($filaMapper($registro)) as $col => $valor) {
                        $sheet->setCellValue([$col + 1, $fila], $valor);
                    }
                    $fila++;
                }
                foreach (range(1, count($encabezados)) as $col) {
                    $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
                }
                $sheet->freezePane('A2');

                $writer = new Xlsx($spreadsheet);

                return response()->streamDownload(function () use ($writer, $spreadsheet): void {
                    try { $writer->save('php://output'); } finally { $spreadsheet->disconnectWorksheets(); }
                }, $nombreArchivo, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
            });
    }
}
