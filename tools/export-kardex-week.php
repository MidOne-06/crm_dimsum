<?php
// Extrae UNA sola semana ISO de kardex_movimientos a Excel. Se invoca como
// proceso PHP nuevo por cada semana (ver export-kardex-historico.sh) para
// que ninguna fuga de memoria entre corridas (Laravel query log, caches
// internos de PhpSpreadsheet, etc.) se acumule entre semanas -- cada
// proceso arranca y muere limpio.

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

Illuminate\Support\Facades\DB::connection()->disableQueryLog();
ini_set('memory_limit', '2048M');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$semana = $argv[1] ?? null;
if (! $semana) {
    fwrite(STDERR, "Uso: php export-kardex-week.php <IYYY-IW>\n");
    exit(1);
}

$outputDir = 'D:/DS-TI/DATA-KARDEX';
if (! is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}

$columns = Illuminate\Support\Facades\Schema::getColumnListing('kardex_movimientos');
$numericColumns = ['entrada', 'salida', 'stock', 'costo_unitario', 'costo_promedio', 'costo_movimiento', 'costo_operacion', 'stock_valorizado'];

$start = microtime(true);

$dbCount = Illuminate\Support\Facades\DB::table('kardex_movimientos')
    ->whereRaw("to_char(fecha, 'IYYY-IW') = ?", [$semana])
    ->count();
$dbSums = Illuminate\Support\Facades\DB::table('kardex_movimientos')
    ->whereRaw("to_char(fecha, 'IYYY-IW') = ?", [$semana])
    ->selectRaw('COALESCE(SUM(entrada), 0) as entrada, COALESCE(SUM(salida), 0) as salida')
    ->first();

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle(str_replace('-', '_S', $semana));
$sheet->fromArray($columns, null, 'A1');

$rowIndex = 2;
$writtenCount = 0;
$sumEntrada = 0.0;
$sumSalida = 0.0;

Illuminate\Support\Facades\DB::table('kardex_movimientos')
    ->whereRaw("to_char(fecha, 'IYYY-IW') = ?", [$semana])
    ->orderBy('id')
    ->chunkById(10000, function ($rows) use ($sheet, $columns, $numericColumns, &$rowIndex, &$writtenCount, &$sumEntrada, &$sumSalida): void {
        foreach ($rows as $row) {
            $col = 'A';
            foreach ($columns as $column) {
                $value = $row->{$column};
                if (in_array($column, $numericColumns, true)) {
                    $value = $value === null ? null : (float) $value;
                }
                $sheet->setCellValue("{$col}{$rowIndex}", $value);
                $col++;
            }
            $sumEntrada += (float) ($row->entrada ?? 0);
            $sumSalida += (float) ($row->salida ?? 0);
            $rowIndex++;
            $writtenCount++;
        }
    });

$filename = "{$outputDir}/kardex-historico-semana-{$semana}.xlsx";
$writer = new Xlsx($spreadsheet);
$writer->save($filename);

$fileSize = filesize($filename);
$countMatches = $writtenCount === $dbCount;
$entradaMatches = abs($sumEntrada - (float) $dbSums->entrada) < 0.01;
$salidaMatches = abs($sumSalida - (float) $dbSums->salida) < 0.01;
$status = ($countMatches && $entradaMatches && $salidaMatches) ? 'OK' : 'DISCREPANCIA';

$result = [
    'semana' => $semana,
    'status' => $status,
    'db_count' => $dbCount,
    'written_count' => $writtenCount,
    'count_ok' => $countMatches,
    'entrada_db' => (float) $dbSums->entrada,
    'entrada_written' => $sumEntrada,
    'entrada_ok' => $entradaMatches,
    'salida_db' => (float) $dbSums->salida,
    'salida_written' => $sumSalida,
    'salida_ok' => $salidaMatches,
    'file' => $filename,
    'size_mb' => round($fileSize / 1024 / 1024, 2),
    'seconds' => round(microtime(true) - $start, 1),
];

echo sprintf(
    "%s: %s -- filas BD=%d escritas=%d | entrada BD=%.2f escrita=%.2f | salida BD=%.2f escrita=%.2f | %s | %.1fs%s",
    $semana, $status, $dbCount, $writtenCount,
    (float) $dbSums->entrada, $sumEntrada,
    (float) $dbSums->salida, $sumSalida,
    basename($filename), $result['seconds'], PHP_EOL,
);

$resultsFile = $outputDir.'/validacion.json';
$all = file_exists($resultsFile) ? json_decode(file_get_contents($resultsFile), true) : [];
if (! is_array($all)) {
    $all = [];
}
$all[$semana] = $result;
file_put_contents($resultsFile, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
