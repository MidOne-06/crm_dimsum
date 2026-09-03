<?php

namespace App\Console\Commands;

use App\Jobs\SincronizarGuiasInternasJob;
use App\Jobs\SincronizarRequerimientosStockJob;
use App\Models\GuiaInternaSincronizacion;
use App\Models\RequerimientoStockSincronizacion;
use Illuminate\Console\Command;

/**
 * Ejecuta las corridas que un usuario creó desde la web (botón "Iniciar
 * extracción") y que quedaron en 'pendiente'.
 *
 * Por qué existe: Filament creaba la fila Y lanzaba Process::start() en el
 * mismo request web. Se comprobó empíricamente que Process::start() NO
 * sobrevive en este contenedor -- el hijo muere en cuanto el proceso PHP
 * que lo creó termina. Por eso las páginas de Filament SOLO crean la fila
 * 'pendiente'; el arranque real siempre pasa por este comando (programado
 * cada minuto), que despacha un job real a la cola del contenedor
 * `worker` -- no un proceso de shell dentro de `scheduler`, que moriría
 * si ese contenedor se recrea en un despliegue mientras la corrida sigue
 * viva (bug real encontrado y corregido -- ver Bitácora en AGENTS.md).
 */
class DespacharSincronizacionesPendientes extends Command
{
    protected $signature = 'extracciones:despachar-pendientes';

    protected $description = 'Arranca las corridas de extracción creadas desde la web que siguen en pendiente (guías internas y requerimientos de stock).';

    public function handle(): int
    {
        $despachadas = 0;
        $despachadas += $this->despacharGuias();
        $despachadas += $this->despacharRequerimientos();

        if ($despachadas > 0) {
            $this->info("Corridas despachadas: {$despachadas}.");
        }

        return self::SUCCESS;
    }

    private function despacharGuias(): int
    {
        if (GuiaInternaSincronizacion::query()->where('estado', 'en_progreso')->exists()) {
            return 0; // ya hay una corriendo; Cache::lock del comando también protege esto, pero evita el fork de más.
        }

        $run = GuiaInternaSincronizacion::query()->where('estado', 'pendiente')->oldest('id')->first();
        if (! $run) {
            return 0;
        }

        $locales = array_values(array_filter((array) ($run->filtros['locales'] ?? [])));
        SincronizarGuiasInternasJob::dispatch($run->id, $locales);
        $this->line("guias-internas: despachada sync-id={$run->id}");

        return 1;
    }

    private function despacharRequerimientos(): int
    {
        if (RequerimientoStockSincronizacion::query()->where('estado', 'en_progreso')->exists()) {
            return 0;
        }

        $run = RequerimientoStockSincronizacion::query()->where('estado', 'pendiente')->oldest('id')->first();
        if (! $run) {
            return 0;
        }

        SincronizarRequerimientosStockJob::dispatch($run->id);
        $this->line("requerimientos-stock: despachada sync-id={$run->id}");

        return 1;
    }
}
