<?php

namespace App\Console\Commands;

use App\Jobs\ExtraerKardexJob;
use App\Jobs\SincronizarGuiasInternasJob;
use App\Jobs\SincronizarRequerimientosStockJob;
use App\Jobs\SincronizarSalidasStockJob;
use App\Models\GuiaInternaSincronizacion;
use App\Models\KardexExtraccion;
use App\Models\KardexExtraccionLocal;
use App\Models\RequerimientoStockSincronizacion;
use App\Models\SalidaStockSincronizacion;
use App\Models\StockCuadreSoporte;
use App\Models\VentaExtraccion;
use App\Services\BackgroundArtisan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

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

    protected $description = 'Detecta y relanza corridas de sincronización histórica (guías, salidas, stock actual, requerimientos, ventas, kardex) que quedaron huérfanas.';

    public function handle(): int
    {
        $umbral = now()->subMinutes((int) $this->option('minutos'));
        $reanudadas = 0;

        $reanudadas += $this->reanudarGuias($umbral);
        $reanudadas += $this->reanudarSalidas($umbral);
        $reanudadas += $this->reanudarStockActual($umbral);
        $reanudadas += $this->reanudarRequerimientos($umbral);
        $reanudadas += $this->reanudarVentas($umbral);
        $reanudadas += $this->reanudarKardex($umbral);

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
            SincronizarGuiasInternasJob::dispatch($run->id, $locales);
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
            SincronizarSalidasStockJob::dispatch($run->id);
            $this->line("salidas-stock: relanzada sync-id={$run->id}");
        }

        return $huerfanas->count();
    }

    /**
     * A diferencia de guías/salidas/requerimientos (que corren "--directo",
     * síncrono, dentro del proceso lanzado por BackgroundArtisan), Stock
     * Actual mantiene el lock de exclusión ('stock-actual:sincronizacion-
     * completa') tomado durante TODA la corrida en modo --directo. Si ese
     * proceso muere (justo el problema que resuelve este comando), el lock
     * queda "fresco" -- se toma de nuevo con cada reintento y expira recién
     * a las 4 horas -- así que relanzar en modo --directo aquí solo reinicia
     * el mismo ciclo: cada intento vuelve a morir y deja un lock nuevo,
     * nunca avanza. En modo normal (sin --directo), el comando solo
     * despacha SincronizarStockActualJob a la cola 'stock-actual' y suelta
     * el lock de inmediato -- el worker real (contenedor `worker`) hace el
     * trabajo pesado, sobrevive independiente de este comando, y si muere a
     * mitad de proceso el propio sistema de colas de Laravel lo reintenta.
     */
    private function reanudarStockActual($umbral): int
    {
        $huerfanas = StockCuadreSoporte::query()
            ->whereIn('estado', ['pendiente', 'en_progreso'])
            ->where('updated_at', '<', $umbral)
            ->get();

        foreach ($huerfanas as $run) {
            Artisan::call('stock-actual:sincronizar', ['--sync-id' => $run->id]);
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
            SincronizarRequerimientosStockJob::dispatch($run->id);
            $this->line("requerimientos-stock: relanzada sync-id={$run->id}");
        }

        return $huerfanas->count();
    }

    /**
     * Ventas no arranca con BackgroundArtisan -- corre sobre el sistema de
     * colas real de Laravel (ventas-pages/ventas-details), así que "relanzar"
     * es reencolar, no volver a lanzar un proceso de shell. Ya existe
     * ventas:reanudar-extraccion para invocación manual, con su propia
     * protección por locked_at (no reencola una venta que un worker sigue
     * procesando de verdad ahora mismo) -- se reutiliza tal cual.
     */
    private function reanudarVentas($umbral): int
    {
        $huerfanas = VentaExtraccion::query()
            ->whereIn('estado', ['pendiente', 'planificando', 'en_progreso'])
            ->where('updated_at', '<', $umbral)
            ->get();

        foreach ($huerfanas as $run) {
            Artisan::call('ventas:reanudar-extraccion', ['id' => $run->id]);
            $this->line("ventas: relanzada id={$run->id}");
        }

        return $huerfanas->count();
    }

    /**
     * Kardex también corre sobre colas reales (queue 'kardex'), no
     * BackgroundArtisan. ExtraerKardexJob ya sabe reanudar una extracción
     * 'en_progreso' (despacha un job por cada local aún 'pendiente') -- solo
     * hace falta liberar los locales que quedaron 'en_progreso' con un lease
     * (procesando_at) vencido antes de volver a despachar, para no competir
     * con un job que de verdad sigue trabajando.
     */
    private function reanudarKardex($umbral): int
    {
        $huerfanas = KardexExtraccion::query()
            ->whereIn('estado', ['pendiente', 'en_progreso'])
            ->where('updated_at', '<', $umbral)
            ->get();

        foreach ($huerfanas as $run) {
            KardexExtraccionLocal::query()
                ->where('extraccion_id', $run->id)
                ->where('estado', 'en_progreso')
                ->where(fn ($query) => $query->whereNull('procesando_at')->orWhere('procesando_at', '<', $umbral))
                ->update(['estado' => 'pendiente', 'procesando_at' => null]);

            ExtraerKardexJob::dispatch($run->id)->onQueue('kardex');
            $this->line("kardex: relanzada id={$run->id}");
        }

        return $huerfanas->count();
    }
}
