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
        if (! $soporte) return;
        try { $service->sincronizar($soporte, $gateway); }
        catch (Throwable $e) { $soporte->update(['estado' => 'fallido', 'mensaje_error' => $e->getMessage(), 'completado_at' => now()]); throw $e; }
    }
}
