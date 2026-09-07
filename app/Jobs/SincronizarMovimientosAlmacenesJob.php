<?php

namespace App\Jobs;

use App\Models\MovimientoAlmacenSincronizacion;
use App\Services\MovimientosAlmacenesGatewayClient;
use App\Services\MovimientosAlmacenesHistoricoService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SincronizarMovimientosAlmacenesJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 7200;

    public function __construct(public int $syncId)
    {
        $this->onQueue('movimientos-almacenes');
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours(4);
    }

    public function handle(MovimientosAlmacenesHistoricoService $service, MovimientosAlmacenesGatewayClient $gateway): void
    {
        $sync = MovimientoAlmacenSincronizacion::find($this->syncId);
        if (! $sync || ! in_array($sync->estado, ['pendiente', 'en_progreso'], true)) return;
        $lock = Cache::lock('movimientos-almacenes:sync', 14400);
        if (! $lock->get()) { $this->release(30); return; }
        try { $service->sincronizar($sync, $gateway); }
        catch (Throwable $exception) { $sync->update(['estado' => 'fallido', 'mensaje_error' => $exception->getMessage(), 'completado_en' => now()]); throw $exception; }
        finally { $lock->release(); }
    }
}
