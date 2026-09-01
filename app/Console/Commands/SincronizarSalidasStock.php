<?php

namespace App\Console\Commands;

use App\Services\SalidasStockGatewayClient;
use App\Services\SalidasStockHistoricoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SincronizarSalidasStock extends Command
{
    protected $signature = 'salidas-stock:sincronizar {--desde=2025-12-01} {--hasta=} {--sync-id= : Reintenta una corrida existente (huérfana/fallida) en vez de crear una nueva}';
    protected $description = 'Sincroniza y reconcilia las Salidas de Stock de Restaurant en la copia local.';

    public function handle(SalidasStockHistoricoService $service, SalidasStockGatewayClient $gateway): int
    {
        $lock = Cache::lock('salidas-stock:sync', 14400);
        if (! $lock->get()) { $this->info('Ya hay una sincronización de Salidas de Stock en curso.'); return self::SUCCESS; }
        try {
            $soporte = filled($this->option('sync-id'))
                ? \App\Models\SalidaStockSincronizacion::query()->findOrFail((int) $this->option('sync-id'))
                : $service->iniciar((string) $this->option('desde'), (string) ($this->option('hasta') ?: now()->toDateString()));
            $this->info(json_encode($service->sincronizar($soporte, $gateway)));
            return self::SUCCESS;
        } finally { $lock->release(); }
    }
}
