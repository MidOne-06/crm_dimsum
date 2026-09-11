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
                $sugerencia = $mapa->get("{$local->local_id}|{$producto->item_id}|{$producto->item_tipo}");
                $cantidad = (float) ($sugerencia?->cantidad_sugerida ?? 0);
                $celda = Coordinate::stringFromColumnIndex($index + 3).$rowNumber;
                $sheet->setCellValue([$index + 3, $rowNumber], $cantidad);
                // Riesgo de quiebre (tramo 1 ya supera el stock proyectado) --
                // pedido explícito del usuario (2026-09-11, barrida de
                // huecos): antes esta alerta solo vivía en pantalla, nunca
                // llegaba al Excel que de verdad circula. Fondo rojo claro,
                // mismo criterio que el badge "¿Riesgo de quiebre?" del
                // Consolidado -- ver hoja "Detalle" para el resto de
                // columnas (desviación, stock de seguridad, etc).
                if ($sugerencia?->riesgo_quiebre) {
                    $sheet->getStyle($celda)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FDE2E1');
                }
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

        $leyendaFila = $filaTotal + 2;
        $sheet->setCellValue("A{$leyendaFila}", 'Fondo rojo: riesgo de quiebre antes de mañana (ver hoja "Detalle").');
        $sheet->getStyle("A{$leyendaFila}")->getFont()->setItalic(true)->setSize(8);

        $this->agregarHojaDetalle($spreadsheet, $mapa->values());

        $writer = new Xlsx($spreadsheet);
        $filename = 'directiva-transferencia-'.$fechaMinima.'.xlsx';

        return response()->streamDownload(function () use ($writer, $spreadsheet): void {
            try { $writer->save('php://output'); } finally { $spreadsheet->disconnectWorksheets(); }
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /**
     * Hoja "Detalle" -- pedido explícito del usuario (2026-09-11, barrida
     * de huecos funcionales): el pivote de arriba solo lleva la cantidad
     * final; nadie que solo abre el Excel puede ver de dónde salió (cuánto
     * es demanda real, cuánto es colchón por variabilidad, si hay riesgo
     * de quiebre) sin volver al Consolidado en pantalla. Una fila por
     * local×producto, mismo criterio y mismos números que el Consolidado.
     *
     * @param  \Illuminate\Support\Collection<int, DirectivaTransferenciaSugerencia>  $sugerencias
     */
    private function agregarHojaDetalle(Spreadsheet $spreadsheet, \Illuminate\Support\Collection $sugerencias): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Detalle');

        $encabezados = [
            'Local', 'Producto', 'SKU', 'Demanda tramo 1', 'Demanda total (2 tramos)',
            'Desv. estándar', 'Stock seguridad', '¿Riesgo de quiebre?', 'Saldo actual',
            'En tránsito', 'Cantidad bruta', '% ajuste', 'Cantidad sugerida',
        ];
        foreach ($encabezados as $index => $titulo) {
            $sheet->setCellValue([$index + 1, 1], $titulo);
        }
        $sheet->getStyle('A1:M1')->getFont()->setBold(true);
        $sheet->getStyle('A1:M1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DCE6F1');

        $fila = 2;
        foreach ($sugerencias->sortBy([['local_nombre', 'asc'], ['item_nombre', 'asc']]) as $s) {
            $sheet->setCellValue([1, $fila], $s->local_nombre);
            $sheet->setCellValue([2, $fila], $s->item_nombre);
            $sheet->setCellValue([3, $fila], $s->item_codigo);
            $sheet->setCellValue([4, $fila], (float) $s->demanda_ventana1);
            $sheet->setCellValue([5, $fila], (float) $s->demanda_promedio);
            $sheet->setCellValue([6, $fila], (float) $s->desviacion_estandar);
            $sheet->setCellValue([7, $fila], $s->stockSeguridad());
            $sheet->setCellValue([8, $fila], $s->riesgo_quiebre ? 'Quiebre antes de mañana' : 'Sin riesgo');
            $sheet->setCellValue([9, $fila], (float) $s->saldo_actual);
            $sheet->setCellValue([10, $fila], (float) $s->cantidad_en_transito);
            $sheet->setCellValue([11, $fila], (float) $s->cantidad_bruta);
            $sheet->setCellValue([12, $fila], (float) $s->porcentaje_ajuste_aplicado);
            $sheet->setCellValue([13, $fila], (float) $s->cantidad_sugerida);
            if ($s->riesgo_quiebre) {
                $sheet->getStyle("A{$fila}:M{$fila}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FDE2E1');
            }
            $fila++;
        }

        $sheet->getStyle('D2:G'.($fila - 1))->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('I2:K'.($fila - 1))->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('L2:M'.($fila - 1))->getNumberFormat()->setFormatCode('#,##0.00');
        foreach (range(1, 13) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }
        $sheet->freezePane('A2');
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
            // Riesgo de quiebre por celda (2026-09-11, barrida de huecos
            // funcionales, pedido explícito del usuario) -- antes esta
            // alerta solo vivía en pantalla, nunca llegaba al PDF que de
            // verdad circula por WhatsApp/impreso a los locales.
            $celdas = $locales->map(function ($local) use ($producto, $mapa): array {
                $sugerencia = $mapa->get("{$local->local_id}|{$producto->item_id}|{$producto->item_tipo}");

                return ['cantidad' => (float) ($sugerencia?->cantidad_sugerida ?? 0), 'riesgo' => (bool) $sugerencia?->riesgo_quiebre];
            });

            return [
                'nombre' => $producto->item_nombre,
                'codigo' => $producto->item_codigo,
                'celdas' => $celdas,
                'total' => $celdas->sum('cantidad'),
            ];
        });

        $totalesColumna = $locales->map(fn ($local, $index): float => $filas->sum(fn (array $fila) => $fila['celdas'][$index]['cantidad']));
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
