<?php

namespace App\Jobs;

use App\Console\Commands\SincronizarReporteRequerimientos;
use App\Models\RequerimientoStockSincronizacion;
use App\Services\RequerimientoStockGatewayClient;
use App\Services\RequerimientoStockHistoricoService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Reemplaza el arranque por BackgroundArtisan (moría si el contenedor
 * `scheduler` se recreaba en un despliegue mientras la corrida seguía
 * viva) por un job real en `worker`. Reutiliza
 * SincronizarReporteRequerimientos::sincronizar() tal cual -- misma lógica,
 * sin duplicarla -- para no divergir del comportamiento ya probado del
 * comando de consola.
 */
class SincronizarRequerimientosStockJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 7200;

    public function __construct(public int $syncId)
    {
        $this->onQueue('requerimientos-stock');
    }

    /**
     * Por tiempo, no por cantidad de intentos -- ver
     * SincronizarGuiasInternasJob::retryUntil() para el detalle completo
     * (64 casos reales de "fallido" falsos en producción por esto).
     */
    public function retryUntil(): \DateTime
    {
        return now()->addHours(4);
    }

    public function handle(
        SincronizarReporteRequerimientos $command,
        RequerimientoStockGatewayClient $gateway,
        RequerimientoStockHistoricoService $historico,
    ): void {
        $run = RequerimientoStockSincronizacion::find($this->syncId);
        // Acepta 'en_progreso' además de 'pendiente': con la reanudación
        // incremental de SincronizarReporteRequerimientos::sincronizar(), una
        // corrida cortada a mitad de camino (deploy, reinicio de worker) ya
        // sabe retomar desde donde quedó -- antes, esta guarda solo permitía
        // 'pendiente', así que la única forma de reanudarla era ReanudarExtraccionesHuerfanas
        // forzando el estado de vuelta a 'pendiente', lo que además perdía el
        // progreso porque sincronizar() no distinguía una corrida nueva de
        // una reanudada.
        if (! $run || ! in_array($run->estado, ['pendiente', 'en_progreso'], true)) return;

        $lock = Cache::lock('requerimientos-stock:sync', 14400);
        if (! $lock->get()) {
            $this->release(30);

            return;
        }

        try {
            $command->sincronizar($run, $gateway, $historico);
        } catch (Throwable $e) {
            $run->update(['estado' => 'fallido', 'mensaje_error' => $e->getMessage(), 'completado_en' => now()]);
            throw $e;
        } finally {
            $lock->release();
        }
    }
}
