<?php

namespace App\Jobs;

use App\Models\DirectivaTransferenciaSolicitud;
use App\Models\GuiaInternaSincronizacion;
use App\Models\KardexExtraccion;
use App\Services\DirectivaTransferenciaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Termina lo que "Generar DT" empezó, sin depender de que el navegador
 * siga abierto -- ver docblock de la migración de `directiva_transferencia_solicitudes`
 * (2026-09-11/12, barrida de huecos funcionales pedida por el usuario).
 *
 * Se auto-reencola cada 30s (en vez de usar reintentos de la cola, que
 * cuentan como fallos) mientras Kardex y/o Guías internas sigan
 * `pendiente`/`en_progreso` -- hasta que ambas terminen (calcula), una
 * falle (marca la solicitud como fallida, sin calcular con datos a medias,
 * mismo criterio que ya usaba la página), o se detecte estancamiento real
 * (20 min sin que el `updated_at` de la corrida avance -- mismo umbral que
 * `estaEstancada()` en Extracción de Guías internas/Movimientos, solo que
 * acá el efecto es marcar la solicitud como fallida en vez de solo avisar
 * en pantalla, porque nadie puede estar mirando la pantalla).
 */
class CalcularDirectivaTrasSincronizacionJob implements ShouldQueue
{
    use Queueable;

    private const MINUTOS_ESTANCAMIENTO = 20;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $solicitudId)
    {
    }

    public function handle(DirectivaTransferenciaService $service): void
    {
        $solicitud = DirectivaTransferenciaSolicitud::find($this->solicitudId);
        if (! $solicitud || $solicitud->estado !== 'pendiente') {
            return;
        }

        $kardex = $solicitud->kardex_extraccion_id ? KardexExtraccion::find($solicitud->kardex_extraccion_id) : null;
        $guias = $solicitud->guia_sincronizacion_id ? GuiaInternaSincronizacion::find($solicitud->guia_sincronizacion_id) : null;

        $kardexListo = ! $kardex || $kardex->estado === 'completado';
        $guiasListo = ! $guias || in_array($guias->estado, ['completado', 'completado_con_errores'], true);
        $kardexFallo = $kardex?->estado === 'fallido';
        $guiasFallo = $guias?->estado === 'fallido';
        $kardexEstancado = $kardex && in_array($kardex->estado, ['pendiente', 'en_progreso'], true)
            && $kardex->updated_at->lt(now()->subMinutes(self::MINUTOS_ESTANCAMIENTO));
        $guiasEstancado = $guias && in_array($guias->estado, ['pendiente', 'en_progreso'], true)
            && $guias->updated_at->lt(now()->subMinutes(self::MINUTOS_ESTANCAMIENTO));

        if ($kardexFallo || $guiasFallo) {
            $solicitud->update([
                'estado' => 'fallido',
                'mensaje_error' => 'Falló '.($kardexFallo ? 'la extracción de Kardex' : 'la sincronización de Guías internas').'. No se calculó nada para no usar datos a medias.',
                'completado_en' => now(),
            ]);

            return;
        }

        if ($kardexEstancado || $guiasEstancado) {
            $solicitud->update([
                'estado' => 'fallido',
                'mensaje_error' => 'Se detectó estancamiento en '.($kardexEstancado ? 'la extracción de Kardex' : 'la sincronización de Guías internas')." (sin avance hace más de ".self::MINUTOS_ESTANCAMIENTO." min). Revisá esa pantalla antes de reintentar.",
                'completado_en' => now(),
            ]);

            return;
        }

        if (! ($kardexListo && $guiasListo)) {
            // Sigue esperando -- se reencola solo, no cuenta como intento
            // fallido de la cola (evita que retryUntil/backoff lo mate).
            self::dispatch($this->solicitudId)->delay(now()->addSeconds(30));

            return;
        }

        $total = $service->calcularParaFecha(
            $solicitud->fecha_referencia->toDateString(),
            [],
            true,
            (float) $solicitud->porcentaje_ajuste_global,
            $solicitud->porcentaje_ajuste_por_local ?? [],
        );

        // Bug real encontrado revalidando en producción un sábado
        // (2026-09-12): con "Sábado sin DT" cargado para los 32 locales
        // (bitácora 2026-09-10), calcularParaFecha() devuelve 0 filas de
        // verdad -- correcto, nadie despacha hoy -- pero como no hubo
        // upsert, `max(calculado_en)` seguía apuntando a la corrida VIEJA
        // de ayer, y el resultado mezclaba "0 sugerencias" con "27 locales"
        // y una fecha de despacho de una corrida distinta -- confuso y
        // engañoso. Con total=0 no hay ninguna corrida fresca que describir.
        if ($total === 0) {
            $solicitud->update([
                'estado' => 'calculado',
                'resultado' => ['total' => 0, 'locales' => 0, 'fecha' => $solicitud->fecha_referencia->toDateString()],
                'mensaje_error' => 'No se generó ninguna sugerencia -- probablemente hoy está marcado como "día sin DT" para todos los locales activos (ver esa pantalla), o no queda ningún local activo (ver "Locales activos").',
                'completado_en' => now(),
            ]);

            return;
        }

        $calculadoEn = \App\Models\DirectivaTransferenciaSugerencia::query()->max('calculado_en');
        $locales = \App\Models\DirectivaTransferenciaSugerencia::query()->where('calculado_en', $calculadoEn)->distinct()->count('local_id');
        $fecha = \App\Models\DirectivaTransferenciaSugerencia::query()->where('calculado_en', $calculadoEn)->min('fecha_despacho');

        $solicitud->update([
            'estado' => 'calculado',
            'resultado' => ['total' => $total, 'locales' => $locales, 'fecha' => (string) $fecha],
            'completado_en' => now(),
        ]);
    }
}
