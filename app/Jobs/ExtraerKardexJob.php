<?php

namespace App\Jobs;

use App\Models\KardexExtraccion;
use App\Models\KardexExtraccionLocal;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Planifica una extracción de Kardex: un job por local (no hace falta paginar
 * como en Ventas -- el reporte de Restaurant.pe trae todo el rango en una
 * sola llamada con pagina=-1/registros=-1).
 */
class ExtraerKardexJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(public int $extraccionId)
    {
    }

    public function handle(): void
    {
        $extraccion = KardexExtraccion::find($this->extraccionId);

        if (! $extraccion || ! in_array($extraccion->estado, ['pendiente', 'en_progreso'], true)) {
            return;
        }

        if ($extraccion->estado === 'en_progreso') {
            KardexExtraccionLocal::query()
                ->where('extraccion_id', $extraccion->id)
                ->where('estado', 'pendiente')
                ->pluck('id')
                ->each(fn (int $id) => ProcesarLocalKardexJob::dispatch($extraccion->id, $id)->onQueue('kardex'));

            return;
        }

        $filtros = $extraccion->filtros ?? [];
        $locales = array_values(array_filter(explode('-', (string) ($filtros['locales'] ?? ''))));

        if (empty($locales)) {
            $extraccion->update([
                'estado' => 'fallido',
                'mensaje_error' => 'No se seleccionó ningún local.',
                'completado_at' => now(),
            ]);

            return;
        }

        $nombres = $filtros['localesNombres'] ?? [];

        DB::transaction(function () use ($extraccion, $locales, $nombres): void {
            foreach ($locales as $localId) {
                KardexExtraccionLocal::query()->insertOrIgnore([
                    'extraccion_id' => $extraccion->id,
                    'local_id' => $localId,
                    'local_nombre' => $nombres[$localId] ?? null,
                    'estado' => 'pendiente',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $extraccion->update([
                'estado' => 'en_progreso',
                'iniciado_at' => $extraccion->iniciado_at ?? now(),
                'locales_total' => count($locales),
            ]);
        });

        KardexExtraccionLocal::query()
            ->where('extraccion_id', $extraccion->id)
            ->where('estado', 'pendiente')
            ->pluck('id')
            ->each(fn (int $id) => ProcesarLocalKardexJob::dispatch($extraccion->id, $id)->onQueue('kardex'));
    }

    /**
     * Mismo motivo que ExtraerVentasJob::failed(): si el worker muere a mitad
     * de proceso, sin este hook la extracción queda "en_progreso" para
     * siempre y bloquea el botón de una nueva.
     */
    public function failed(?Throwable $exception): void
    {
        $extraccion = KardexExtraccion::find($this->extraccionId);

        if (! $extraccion || $extraccion->estado === 'completado') {
            return;
        }

        $ahora = now();

        // Evita dejar cabeceras "en progreso" y detalles pendientes si el
        // job planificador muere antes de enviar todos los trabajos por local.
        $extraccion->locales()->whereIn('estado', ['pendiente', 'en_progreso'])->update([
            'estado' => 'fallido',
            'mensaje_error' => $exception?->getMessage() ?? 'El planificador de la extracción se detuvo.',
            'completado_at' => $ahora,
        ]);

        $totales = $extraccion->locales()
            ->selectRaw("count(*) filter (where estado = 'completado') as procesados")
            ->selectRaw("count(*) filter (where estado = 'fallido') as fallidos")
            ->selectRaw('coalesce(sum(movimientos_guardados), 0) as movimientos')
            ->first();

        $extraccion->update([
            'estado' => 'fallido',
            'mensaje_error' => $exception?->getMessage() ?? 'El worker se detuvo antes de terminar la extracción.',
            'locales_procesados' => (int) ($totales->procesados ?? 0),
            'locales_fallidos' => (int) ($totales->fallidos ?? 0),
            'movimientos_guardados' => (int) ($totales->movimientos ?? 0),
            'completado_at' => $ahora,
        ]);
    }
}
