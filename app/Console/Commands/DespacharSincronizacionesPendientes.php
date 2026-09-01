<?php

namespace App\Console\Commands;

use App\Models\GuiaInternaSincronizacion;
use App\Models\RequerimientoStockSincronizacion;
use App\Services\BackgroundArtisan;
use Illuminate\Console\Command;

/**
 * Ejecuta las corridas que un usuario creó desde la web (botón "Iniciar
 * extracción") y que quedaron en 'pendiente'.
 *
 * Por qué existe: Filament creaba la fila Y lanzaba Process::start() en el
 * mismo request web. Se comprobó empíricamente (con un `sleep 30` de
 * prueba, tanto desde un request web como desde consola dentro del propio
 * contenedor de producción) que Process::start() NO sobrevive aquí en
 * absoluto -- el hijo muere en cuanto el proceso PHP que lo creó termina,
 * sin importar si ese proceso era un worker de PHP-FPM o un comando de
 * consola de corta vida. Por eso las páginas de Filament ahora SOLO crean
 * la fila 'pendiente'; el arranque real siempre pasa por este comando
 * (programado cada minuto), usando BackgroundArtisan -- el mismo mecanismo
 * de `&` de shell que usa el propio scheduler de Laravel para
 * `runInBackground()`, que sí se comprobó que sobrevive.
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
        $args = ['guias-internas:sincronizar', '--sync-id='.$run->id];
        foreach ($locales as $local) {
            $args[] = '--locales='.$local;
        }
        BackgroundArtisan::start($args);
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

        BackgroundArtisan::start(['requerimientos-stock:sincronizar-reporte', '--sync-id='.$run->id]);
        $this->line("requerimientos-stock: despachada sync-id={$run->id}");

        return 1;
    }
}
