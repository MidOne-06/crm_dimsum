<?php

namespace App\Console\Commands;

use App\Jobs\SincronizarStockActualJob;
use App\Services\StockActualHistoricoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SincronizarStockActual extends Command
{
    protected $signature = 'stock-actual:sincronizar {--desde=2020-01-01} {--hasta=} {--directo : Ejecuta en este proceso, sin requerir worker}';
    protected $description = 'Sincroniza todos los cuadres y detalles de Restaurant a la copia local.';

    public function handle(StockActualHistoricoService $service): int
    {
        $lock = Cache::lock('stock-actual:sincronizacion-completa', 14400);
        if (! $lock->get()) {
            $this->info('Ya hay una sincronización de Stock Actual en curso.');
            return self::SUCCESS;
        }

        try {
        $soporte = $service->iniciar([
            'locales' => '', 'estado' => '-1', 'tipo' => '-1',
            'fechaInicio' => (string) $this->option('desde'),
            'fechaFin' => (string) ($this->option('hasta') ?: now()->toDateString()),
            'itemIdList' => '', 'itemTipoList' => '',
        ]);

        if ($this->option('directo')) {
            try {
                $service->sincronizar($soporte, app(\App\Services\StockGatewayClient::class));
                $this->info("Sincronización {$soporte->id} completada.");
            } catch (\Throwable $exception) {
                $soporte->update(['estado' => 'fallido', 'mensaje_error' => $exception->getMessage(), 'completado_at' => now()]);
                throw $exception;
            }
        } else {
            SincronizarStockActualJob::dispatch($soporte->id);
            $this->info("Sincronización {$soporte->id} enviada a segundo plano.");
        }

        return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
