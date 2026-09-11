<?php

namespace App\Services;

use App\Models\BrandingSetting;
use App\Models\DirectivaTransferenciaSugerencia;
use App\Models\ProductoPresentacionDespacho;
use Dompdf\Dompdf;
use Dompdf\Options as DompdfOptions;
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
 * Excel/PDF de la Directiva de Transferencia (pivote producto x local),
 * extraído de `DirectivaTransferenciaConsolidado` (2026-09-11, pedido
 * explícito del usuario: "Iniciar Directiva de Transferencia" también
 * necesita ofrecer estos mismos exports apenas termina de calcular, sin
 * duplicar los ~150 líneas de armado de Excel/PDF en 2 páginas distintas).
 * `$fechaMinima` es la misma fecha que antes vivía como
 * `fechaMinimaAMostrar()` en el Consolidado -- "todas las fechas de
 * despacho desde acá en adelante" (cada local puede tener la suya propia).
 */
class DirectivaTransferenciaExportService
{
    /**
     * Arma los 3 datasets del pivote (locales, productos, mapa local×item →
     * sugerencia) para todas las fechas de despacho desde `$fechaMinima` en
     * adelante -- compartido entre generarExcel() y generarPdf() para no
     * cruzar lo mismo dos veces.
     *
     * @return array{locales: \Illuminate\Support\Collection, productos: \Illuminate\Support\Collection, mapa: \Illuminate\Support\Collection}
     */
    public function pivotData(string $fechaMinima): array
    {
        $sugerenciasQuery = DirectivaTransferenciaSugerencia::query()->where('fecha_despacho', '>=', $fechaMinima);
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
     * usuario para repartir stock inicial entre locales.
     */
    public function generarExcel(string $fechaMinima): StreamedResponse
    {
        ['locales' => $locales, 'productos' => $productos, 'mapa' => $mapa] = $this->pivotData($fechaMinima);

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
        $filename = 'directiva-transferencia-'.$fechaMinima.'.xlsx';

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
    public function logoDataUri(): ?string
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

    /** Mismo pivote que generarExcel(), en PDF A3 apaisado. */
    public function generarPdf(string $fechaMinima): StreamedResponse
    {
        ['locales' => $locales, 'productos' => $productos, 'mapa' => $mapa] = $this->pivotData($fechaMinima);

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
            'fecha' => Carbon::parse($fechaMinima)->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY'),
            'locales' => $locales,
            'filas' => $filas,
            'totalesColumna' => $totalesColumna,
            'granTotal' => $granTotal,
            'logoDataUri' => $this->logoDataUri(),
            'usuarioNombre' => auth()->user()?->name ?? 'Sistema',
            'generadoEn' => now()->format('d/m/Y H:i'),
        ])->render());
        $pdf->render();

        $filename = 'directiva-transferencia-'.$fechaMinima.'.pdf';

        return response()->streamDownload(fn () => print $pdf->output(), $filename, ['Content-Type' => 'application/pdf']);
    }
}
