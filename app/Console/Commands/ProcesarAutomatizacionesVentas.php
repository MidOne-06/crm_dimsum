<?php

namespace App\Console\Commands;

use App\Jobs\ExtraerVentasJob;
use App\Models\VentaExtraccion;
use App\Models\VentaExtraccionAutomatizacion;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ProcesarAutomatizacionesVentas extends Command
{
    protected $signature = 'ventas:procesar-automatizaciones';
    protected $description = 'Inicia el siguiente bloque pendiente de las automatizaciones de ventas.';

    public function handle(): int
    {
        $automatizacion = VentaExtraccionAutomatizacion::query()
            ->whereIn('estado', ['pendiente', 'en_progreso'])
            ->orderBy('id')
            ->first();

        if (! $automatizacion) {
            return self::SUCCESS;
        }

        if (VentaExtraccion::query()->whereIn('estado', ['pendiente', 'planificando', 'en_progreso'])->exists()) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($automatizacion): void {
            $automatizacion->refresh();
            $segmentos = $automatizacion->segmentos ?? [];

            if ($automatizacion->extraccion_actual_id) {
                $anterior = VentaExtraccion::find($automatizacion->extraccion_actual_id);

                if (! $anterior || $anterior->estado === 'fallido') {
                    $automatizacion->update([
                        'estado' => 'fallido',
                        'mensaje_error' => $anterior?->mensaje_error ?? 'No se encontró el bloque automático anterior.',
                    ]);

                    return;
                }

                if ($anterior->estado !== 'completado') {
                    return;
                }

                $segmentos[$automatizacion->indice_actual]['estado'] = 'completado';
                $segmentos[$automatizacion->indice_actual]['extraccion_id'] = $anterior->id;
                $automatizacion->increment('indice_actual');
                $automatizacion->update(['segmentos' => $segmentos, 'extraccion_actual_id' => null]);
                $automatizacion->refresh();
            }

            if ($automatizacion->indice_actual >= count($segmentos)) {
                $automatizacion->update(['estado' => 'completado', 'extraccion_actual_id' => null]);

                return;
            }

            $segmento = $segmentos[$automatizacion->indice_actual];

            try {
                $extraccion = VentaExtraccion::create([
                    'estado' => 'pendiente',
                    'filtros' => $segmento['filtros'],
                    'iniciado_por' => $automatizacion->iniciado_por,
                ]);
            } catch (QueryException) {
                return;
            }

            $segmentos[$automatizacion->indice_actual]['estado'] = 'en_progreso';
            $segmentos[$automatizacion->indice_actual]['extraccion_id'] = $extraccion->id;
            $automatizacion->update([
                'estado' => 'en_progreso',
                'segmentos' => $segmentos,
                'extraccion_actual_id' => $extraccion->id,
            ]);

            ExtraerVentasJob::dispatch($extraccion->id);
            $this->info("Iniciado bloque {$automatizacion->indice_actual} en extracción #{$extraccion->id}.");
        });

        return self::SUCCESS;
    }
}
