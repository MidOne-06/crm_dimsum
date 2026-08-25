<?php

namespace App\Console\Commands;

use App\Jobs\ProcesarLoteVentasDetalleJob;
use App\Jobs\ProcesarPaginaVentasJob;
use App\Models\VentaExtraccion;
use App\Models\VentaExtraccionPagina;
use App\Models\VentaExtraccionVenta;
use Illuminate\Console\Command;

class ReanudarExtraccionVentas extends Command
{
    protected $signature = 'ventas:reanudar-extraccion {id}';

    protected $description = 'Recupera ventas y páginas estancadas de una extracción y las reencola.';

    public function handle(): int
    {
        $id = (int) $this->argument('id');
        $extraccion = VentaExtraccion::findOrFail($id);

        if (! in_array($extraccion->estado, ['pendiente', 'planificando', 'en_progreso'], true)) {
            $this->error("La extracción #{$id} está en estado '{$extraccion->estado}', no hay nada que reanudar.");

            return self::FAILURE;
        }

        // Páginas: la tabla no tiene locked_at, así que no hay forma de
        // distinguir "un worker la está procesando ahora mismo" de "el
        // worker murió a mitad de proceso". Este comando es de invocación
        // manual (el operador ya confirmó que la extracción está estancada),
        // así que se reencolan todas las no completadas.
        $paginaIds = VentaExtraccionPagina::where('extraccion_id', $id)
            ->whereIn('estado', ['pendiente', 'en_progreso'])
            ->pluck('id');

        VentaExtraccionPagina::whereIn('id', $paginaIds)->update(['estado' => 'pendiente']);

        foreach ($paginaIds as $paginaId) {
            ProcesarPaginaVentasJob::dispatch($id, $paginaId)->onQueue('ventas-pages');
        }

        // Ventas: aquí sí hay locked_at (mismo mecanismo de lease que usa
        // ProcesarLoteVentasDetalleJob), así que solo se liberan las que
        // llevan más de 10 minutos con el lease tomado -- si sigue vigente,
        // es porque un worker la sigue procesando de verdad ahora mismo. Sin
        // este chequeo, reanudar mientras un worker está a mitad de trabajo
        // hace que dos procesos terminen escribiendo la misma venta.
        $ventaIds = VentaExtraccionVenta::where('extraccion_id', $id)
            ->where(function ($query): void {
                $query->where('estado', 'pendiente')
                    ->orWhere(fn ($stale) => $stale->where('estado', 'en_progreso')->where('locked_at', '<', now()->subMinutes(10)));
            })
            ->pluck('id');

        VentaExtraccionVenta::whereIn('id', $ventaIds)->update(['estado' => 'pendiente', 'locked_at' => null]);

        foreach ($ventaIds->chunk(25) as $lote) {
            ProcesarLoteVentasDetalleJob::dispatch($id, $lote->all())->onQueue('ventas-details');
        }

        // Solo se fuerza a en_progreso si de verdad se reencoló algo -- de lo
        // contrario queda una extracción marcada "activa" sin ningún job
        // real detrás que la lleve a completado, bloqueando el botón de
        // iniciar una nueva extracción para siempre.
        if ($paginaIds->isNotEmpty() || $ventaIds->isNotEmpty()) {
            $extraccion->update(['estado' => 'en_progreso']);
        }

        $this->info("Reencoladas {$paginaIds->count()} página(s) y {$ventaIds->count()} venta(s).");

        return self::SUCCESS;
    }
}
