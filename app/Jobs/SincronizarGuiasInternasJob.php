<?php

namespace App\Jobs;

use App\Models\GuiaInternaSincronizacion;
use App\Services\GuiasInternasGatewayClient;
use App\Services\GuiasInternasHistoricoService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Reemplaza el arranque por BackgroundArtisan (proceso de shell dentro del
 * contenedor `scheduler`, que muere si ese contenedor se recrea en un
 * despliegue) por un job real en el contenedor `worker`, que no se recrea
 * en un deploy normal de la app. "Detener" en la UI ya no mata un PID real
 * (correría en un worker compartido con otras colas -- matarlo tumbaría
 * todo lo demás que esté procesando) -- GuiasInternasHistoricoService
 * revisa el estado entre cada página y para solo ahí, nunca a mitad de
 * guardar una.
 */
class SincronizarGuiasInternasJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 7200;

    /** @param array<int, string> $locales */
    public function __construct(
        public int $syncId,
        public array $locales = [],
        public string $estado = '-1',
        public string $filtroFecha = '1',
    ) {
        $this->onQueue('guias-internas');
    }

    /**
     * Reintentar por tiempo, no por cantidad de intentos: cuando el lock
     * está ocupado (otra corrida real trabajando, o un duplicado esperando
     * su turno), el job se reencola cada 30s con release() -- eso cuenta
     * como "intento" para Laravel. Con un $tries fijo bajo (2), un
     * despacho duplicado que solo estaba esperando el lock se marcaba
     * "fallido" (MaxAttemptsExceededException) a los ~60s sin que nada
     * hubiera fallado de verdad -- se comprobó en producción: 64 jobs
     * terminaron en failed_jobs así, mientras la corrida real seguía
     * avanzando sin problema. retryUntil() usa el mismo horizonte que el
     * TTL del lock (4h) en vez de contar reintentos.
     */
    public function retryUntil(): \DateTime
    {
        return now()->addHours(4);
    }

    public function handle(GuiasInternasHistoricoService $service, GuiasInternasGatewayClient $gateway): void
    {
        $sync = GuiaInternaSincronizacion::find($this->syncId);
        if (! $sync || ! in_array($sync->estado, ['pendiente', 'en_progreso'], true)) return;

        $lock = Cache::lock('guias-internas:sync', 14400);
        if (! $lock->get()) {
            $this->release(30);

            return;
        }

        try {
            $service->sincronizar($sync, $gateway, $this->locales, $this->estado, $this->filtroFecha);
        } catch (Throwable $e) {
            $sync->update(['estado' => 'fallido', 'mensaje_error' => $e->getMessage(), 'completado_en' => now()]);
            throw $e;
        } finally {
            $lock->release();
        }
    }
}
