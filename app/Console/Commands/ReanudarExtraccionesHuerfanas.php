<?php

namespace App\Console\Commands;

use App\Models\GuiaInternaSincronizacion;
use App\Models\RequerimientoStockSincronizacion;
use App\Models\SalidaStockSincronizacion;
use App\Models\StockCuadreSoporte;
use App\Services\BackgroundArtisan;
use Illuminate\Console\Command;

/**
 * Autocura corridas de sincronización histórica que quedaron huérfanas: el
 * proceso PHP que las ejecutaba murió (sesión SSH cortada, servidor
 * reiniciado, `kill`) pero la fila se quedó marcada 'pendiente'/'en_progreso'
 * para siempre, porque nada más las reintenta. Antes de este comando, cada
 * corrida huérfana requería intervención manual (ver historial de
 * GuiaInternaSincronizacion ids 8, 9, 17-22: todas murieron así).
 *
 * Heurística de "huérfana": estado activo sin avance en el último umbral de
 * minutos. Es la misma que ya usa ReanudarExtraccionVentas para páginas sin
 * locked_at -- no hay forma barata de saber "¿el proceso sigue vivo de
 * verdad?" desde Laravel, así que se asume que si no avanzó en N minutos,
 * ya no hay nadie trabajándola.
 *
 * El relanzamiento usa BackgroundArtisan (no Process::start() directo): se
 * comprobó empíricamente que Process::start() no sobrevive en este
 * contenedor, así que hasta este fix los "relanzada sync-id=X" que
 * imprimía este comando no arrancaban nada de verdad -- ver
 * App\Services\BackgroundArtisan para el detalle de la prueba.
 */
class ReanudarExtraccionesHuerfanas extends Command
{
    protected $signature = 'extracciones:reanudar-huerfanas {--minutos=10 : Minutos sin avance para considerar una corrida huérfana}';

    protected $description = 'Detecta y relanza corridas de sincronización histórica (guías, salidas, stock actual, requerimientos) que quedaron huérfanas.';

    public function handle(): int
    {
        $umbral = now()->subMinutes((int) $this->option('minutos'));
        $reanudadas = 0;

        $reanudadas += $this->reanudarGuias($umbral);
        $reanudadas += $this->reanudarSalidas($umbral);
        $reanudadas += $this->reanudarStockActual($umbral);
        $reanudadas += $this->reanudarRequerimientos($umbral);

        $this->info("Corridas huérfanas relanzadas: {$reanudadas}.");

        return self::SUCCESS;
    }

    private function reanudarGuias($umbral): int
    {
        $huerfanas = GuiaInternaSincronizacion::query()
            ->whereIn('estado', ['pendiente', 'en_progreso'])
            ->where('updated_at', '<', $umbral)
            ->get();

        foreach ($huerfanas as $run) {
            $locales = array_values(array_filter((array) ($run->filtros['locales'] ?? [])));
            $args = ['guias-internas:sincronizar', '--sync-id='.$run->id];
            foreach ($locales as $local) $args[] = '--locales='.$local;
            BackgroundArtisan::start($args);
            $this->line("guias-internas: relanzada sync-id={$run->id}");
        }

        return $huerfanas->count();
    }

    private function reanudarSalidas($umbral): int
    {
        $huerfanas = SalidaStockSincronizacion::query()
            ->whereIn('estado', ['pendiente', 'en_progreso'])
            ->where('updated_at', '<', $umbral)
            ->get();

        foreach ($huerfanas as $run) {
            BackgroundArtisan::start(['salidas-stock:sincronizar', '--sync-id='.$run->id]);
            $this->line("salidas-stock: relanzada sync-id={$run->id}");
        }

        return $huerfanas->count();
    }

    private function reanudarStockActual($umbral): int
    {
        $huerfanas = StockCuadreSoporte::query()
            ->whereIn('estado', ['pendiente', 'en_progreso'])
            ->where('updated_at', '<', $umbral)
            ->get();

        foreach ($huerfanas as $run) {
            BackgroundArtisan::start(['stock-actual:sincronizar', '--directo', '--sync-id='.$run->id]);
            $this->line("stock-actual: relanzada sync-id={$run->id}");
        }

        return $huerfanas->count();
    }

    private function reanudarRequerimientos($umbral): int
    {
        // El comando de requerimientos exige estado='pendiente' para
        // arrancar (ver SincronizarReporteRequerimientos::handle), así que
        // una corrida huérfana en 'en_progreso' primero se resetea.
        $huerfanas = RequerimientoStockSincronizacion::query()
            ->whereIn('estado', ['pendiente', 'en_progreso'])
            ->where('updated_at', '<', $umbral)
            ->get();

        foreach ($huerfanas as $run) {
            if ($run->estado === 'en_progreso') {
                $run->update(['estado' => 'pendiente']);
            }
            BackgroundArtisan::start(['requerimientos-stock:sincronizar-reporte', '--sync-id='.$run->id]);
            $this->line("requerimientos-stock: relanzada sync-id={$run->id}");
        }

        return $huerfanas->count();
    }
}
