<?php

namespace App\Jobs;

use App\Models\KardexExtraccion;
use App\Models\KardexExtraccionLocal;
use App\Models\KardexMovimiento;
use App\Services\KardexGatewayClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Descarga el mismo reporte xlsx que "Descargar" en Kardex General (para un
 * local, todos los almacenes) y guarda sus filas en kardex_movimientos.
 * Reemplaza por rango (local_id + fecha) en cada corrida -- el reporte no
 * trae un id de movimiento único con el que hacer upsert fila a fila.
 */
class ProcesarLocalKardexJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public array $backoff = [30, 120, 300];

    public function __construct(public int $extraccionId, public int $extraccionLocalId)
    {
    }

    public function handle(KardexGatewayClient $gateway): void
    {
        $extraccion = KardexExtraccion::find($this->extraccionId);
        $local = KardexExtraccionLocal::whereKey($this->extraccionLocalId)->where('extraccion_id', $this->extraccionId)->first();

        if (! $extraccion || ! $local || $extraccion->estado !== 'en_progreso') {
            return;
        }

        $claimed = KardexExtraccionLocal::whereKey($local->id)->where('estado', 'pendiente')->update(['estado' => 'en_progreso']);
        if (! $claimed) {
            return;
        }

        $filtros = $extraccion->filtros ?? [];

        try {
            $reporte = $gateway->reporte([
                'local_id' => $local->local_id,
                'almacen_id' => '-1',
                'motivo' => (string) ($filtros['motivo'] ?? '-1'),
                'fecha_inicio' => $filtros['fechaInicio'],
                'fecha_fin' => $filtros['fechaFin'],
                'kardex_valorizado' => '1',
                'vercostosinimpuesto' => '0',
                'tipo_producto' => '1',
                'tipo_insumo' => '1',
                'tipo_derivado' => '1',
                'type' => 'excel',
                'version' => '1',
            ]);

            $filas = $this->parsear($reporte['content'], $local->local_id, $local->local_nombre, $local->id);
            $guardadas = $this->reemplazar($local->local_id, $filtros['fechaInicio'], $filtros['fechaFin'], $filas);

            $local->update(['estado' => 'completado', 'movimientos_guardados' => $guardadas]);
            $extraccion->increment('locales_procesados');
            $extraccion->increment('movimientos_guardados', $guardadas);
            KardexExtraccion::finalizarSiListo($extraccion->id);
        } catch (Throwable $exception) {
            $local->update(['estado' => 'fallido', 'mensaje_error' => $exception->getMessage()]);
            $extraccion->increment('locales_fallidos');
            KardexExtraccion::finalizarSiListo($extraccion->id);

            throw $exception;
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function parsear(string $binaryXlsx, string $localId, ?string $localNombre, int $extraccionLocalId): array
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'kardex_').'.xlsx';
        file_put_contents($tmpFile, $binaryXlsx);

        $spreadsheet = null;

        try {
            $spreadsheet = IOFactory::load($tmpFile);
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = $sheet->getHighestRow();

            $filas = [];
            $now = now();

            for ($row = 2; $row <= $highestRow; $row++) {
                $categoria = $this->str($sheet, 1, $row);
                $fechaRaw = trim((string) $sheet->getCell([5, $row])->getFormattedValue());

                if ($categoria === null && $fechaRaw === '') {
                    continue;
                }

                $fecha = $this->parseFecha($fechaRaw);
                if (! $fecha) {
                    continue;
                }

                $horaRaw = trim((string) $sheet->getCell([6, $row])->getFormattedValue());
                $fechaHora = $horaRaw !== '' ? Carbon::parse($fecha->format('Y-m-d').' '.$horaRaw) : $fecha->copy();

                $filas[] = [
                    'extraccion_local_id' => $extraccionLocalId,
                    'local_id' => $localId,
                    'local_nombre' => $localNombre,
                    'categoria' => $categoria,
                    'tipo_item' => $this->str($sheet, 2, $row),
                    'item_id' => $this->str($sheet, 3, $row),
                    'item_nombre' => $this->str($sheet, 4, $row),
                    'fecha' => $fecha->format('Y-m-d'),
                    'hora' => $horaRaw ?: null,
                    'fecha_hora' => $fechaHora,
                    'almacen' => $this->str($sheet, 7, $row),
                    'motivo' => $this->str($sheet, 8, $row),
                    'observacion' => $this->str($sheet, 9, $row),
                    'doc_entidad' => $this->str($sheet, 10, $row),
                    'entidad' => $this->str($sheet, 11, $row),
                    'unidad_medida' => $this->str($sheet, 12, $row),
                    'entrada' => $this->num($sheet, 13, $row),
                    'salida' => $this->num($sheet, 14, $row),
                    'stock' => $this->num($sheet, 15, $row),
                    'costo_unitario' => $this->num($sheet, 16, $row),
                    'costo_promedio' => $this->num($sheet, 17, $row),
                    'costo_movimiento' => $this->num($sheet, 18, $row),
                    'costo_operacion' => $this->num($sheet, 19, $row),
                    'stock_valorizado' => $this->num($sheet, 20, $row),
                    'canal_venta' => $this->str($sheet, 21, $row),
                    'id_producto_venta' => $this->str($sheet, 22, $row),
                    'cod_interno' => $this->str($sheet, 23, $row),
                    'producto' => $this->str($sheet, 24, $row),
                    'tienda' => $this->str($sheet, 25, $row),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            return $filas;
        } finally {
            $spreadsheet?->disconnectWorksheets();
            @unlink($tmpFile);
        }
    }

    private function str(Worksheet $sheet, int $col, int $row): ?string
    {
        $value = trim((string) $sheet->getCell([$col, $row])->getFormattedValue());

        return $value === '' || $value === '-' ? null : $value;
    }

    private function num(Worksheet $sheet, int $col, int $row): float
    {
        $value = $sheet->getCell([$col, $row])->getValue();

        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function parseFecha(string $raw): ?Carbon
    {
        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('d-m-Y', $raw)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<int, array<string, mixed>> $filas */
    private function reemplazar(string $localId, string $fechaInicio, string $fechaFin, array $filas): int
    {
        return DB::transaction(function () use ($localId, $fechaInicio, $fechaFin, $filas): int {
            KardexMovimiento::query()
                ->where('local_id', $localId)
                ->whereBetween('fecha', [$fechaInicio, $fechaFin])
                ->delete();

            foreach (array_chunk($filas, 500) as $lote) {
                KardexMovimiento::query()->insert($lote);
            }

            return count($filas);
        });
    }

    public function failed(?Throwable $exception): void
    {
        KardexExtraccionLocal::whereKey($this->extraccionLocalId)->update([
            'estado' => 'fallido',
            'mensaje_error' => $exception?->getMessage() ?? 'No se pudo procesar el local.',
        ]);

        $extraccion = KardexExtraccion::find($this->extraccionId);
        if ($extraccion && ! in_array($extraccion->estado, ['completado', 'fallido'], true)) {
            KardexExtraccion::finalizarSiListo($this->extraccionId);
        }
    }
}
