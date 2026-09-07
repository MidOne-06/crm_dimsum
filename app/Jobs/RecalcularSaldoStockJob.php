<?php

namespace App\Jobs;

use App\Services\StockSaldoRecalculadorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Se dispara automáticamente al terminar cada extracción real de Kardex de
 * un local (ver el hook en ProcesarLocalKardexJob::handle()) -- no corre
 * por cron aparte. Si el local todavía no tiene stock inicial confirmado,
 * el servicio no hace nada (no es un error).
 */
class RecalcularSaldoStockJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public string $localId)
    {
    }

    public function handle(StockSaldoRecalculadorService $service): void
    {
        $service->recalcularLocal($this->localId);
    }
}
