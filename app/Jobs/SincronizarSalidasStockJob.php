<?php

namespace App\Jobs;

use App\Models\SalidaStockSincronizacion;
use App\Services\SalidasStockGatewayClient;
use App\Services\SalidasStockHistoricoService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Antes, reanudar una corrida huérfana de Salidas de Stock lanzaba
 * `salidas-stock:sincronizar --sync-id=X` con BackgroundArtisan DENTRO del
 * contenedor `scheduler` -- si ese contenedor se recreaba (cualquier
 * despliegue) mientras la corrida seguía viva, moría a mitad de camino y
 * dejaba el lock 'salidas-stock:sync' recién tomado, bloqueando cualquier
 * otro intento hasta por 4 horas sin avanzar nunca (mismo bug real que se
 * encontró y corrigió hoy en Stock Actual). Este job corre en el
 * contenedor `worker`, que no se recrea en un deploy normal de la app.
 */
class SincronizarSalidasStockJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 7200;

    public int $tries = 2;

    public function __construct(public int $soporteId)
    {
        $this->onQueue('salidas-stock');
    }

    public function handle(SalidasStockHistoricoService $service, SalidasStockGatewayClient $gateway): void
    {
        $soporte = SalidaStockSincronizacion::find($this->soporteId);
        if (! $soporte) return;

        // Mismo lock que ya usa el comando de consola, para que una corrida
        // programada (scheduler, incremental) y una relanzada desde acá
        // nunca corran en paralelo sobre la misma tabla.
        $lock = Cache::lock('salidas-stock:sync', 14400);
        if (! $lock->get()) {
            $this->release(30);

            return;
        }

        try {
            $service->sincronizar($soporte, $gateway);
        } catch (Throwable $e) {
            $soporte->update(['estado' => 'fallido', 'mensaje_error' => $e->getMessage(), 'completado_en' => now()]);
            throw $e;
        } finally {
            $lock->release();
        }
    }
}
