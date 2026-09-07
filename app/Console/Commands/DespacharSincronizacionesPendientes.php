<?php

namespace App\Console\Commands;

use App\Jobs\SincronizarGuiasInternasJob;
use App\Jobs\SincronizarMovimientosAlmacenesJob;
use App\Jobs\SincronizarRequerimientosStockJob;
use App\Models\GuiaInternaSincronizacion;
use App\Models\MovimientoAlmacenSincronizacion;
use App\Models\RequerimientoStockSincronizacion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

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

    protected $description = 'Arranca las corridas de extracción creadas desde la web que siguen en pendiente.';

    public function handle(): int
    {
        $despachadas = 0;
        $despachadas += $this->despacharGuias();
        $despachadas += $this->despacharMovimientosAlmacenes();
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

    private function despacharMovimientosAlmacenes(): int
    {
        // Reclamar la corrida antes de encolarla. El scheduler corre cada
        // minuto y, si el worker está ocupado o caído, una fila pendiente
        // podía ser encolada varias veces para el mismo sync-id.
        $run = DB::transaction(function (): ?MovimientoAlmacenSincronizacion {
            if (MovimientoAlmacenSincronizacion::query()->where('estado', 'en_progreso')->exists()) {
                return null;
            }

            $pending = MovimientoAlmacenSincronizacion::query()
                ->where('estado', 'pendiente')
                ->oldest('id')
                ->lockForUpdate()
                ->first();

            if (! $pending) {
                return null;
            }

            $pending->forceFill([
                'estado' => 'en_progreso',
                'iniciado_en' => now(),
                'mensaje_error' => null,
            ])->save();

            return $pending;
        });

        if (! $run) return 0;

        try {
            SincronizarMovimientosAlmacenesJob::dispatch($run->id);
        } catch (Throwable $exception) {
            // Si la cola no está disponible, dejarla reintentable en el
            // siguiente tick en vez de mantenerla falsamente en progreso.
            $run->forceFill([
                'estado' => 'pendiente',
                'iniciado_en' => null,
                'mensaje_error' => $exception->getMessage(),
            ])->save();
            throw $exception;
        }

        $this->line("movimientos-almacenes: despachada sync-id={$run->id}");
        return 1;
    }
}
