<?php

namespace App\Jobs;

use App\Models\StockCuadreSoporte;
use App\Services\StockActualHistoricoService;
use App\Services\StockGatewayClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SincronizarStockActualJob implements ShouldQueue
{
    use Queueable;
    public int $timeout = 7200;
    public int $tries = 2;

    public function __construct(public int $soporteId) { $this->onQueue('stock-actual'); }

    public function handle(StockActualHistoricoService $service, StockGatewayClient $gateway): void
    {
        $soporte = StockCuadreSoporte::find($this->soporteId);
        // Sin este chequeo, un despacho duplicado (ej. reanudarStockActual
        // corriendo cada 10 min mientras la corrida anterior sigue viva más
        // de 10 min) vuelve a sincronizar desde cero un rango ya completado
        // -- se comprobó en producción: 4 de 5 despachos "huérfanos"
        // apuntaban a corridas que ya estaban en estado 'completado'.
        if (! $soporte || ! in_array($soporte->estado, ['pendiente', 'en_progreso'], true)) return;
        try { $service->sincronizar($soporte, $gateway); }
        catch (Throwable $e) { $soporte->update(['estado' => 'fallido', 'mensaje_error' => $e->getMessage(), 'completado_at' => now()]); throw $e; }
    }
}
