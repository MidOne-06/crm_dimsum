<?php

namespace App\Console\Commands;

use App\Jobs\ProcesarLoteVentasDetalleJob;
use App\Models\VentaExtraccion;
use App\Models\VentaExtraccionVenta;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReprocesarVentasFallidas extends Command
{
    protected $signature = 'ventas:reprocesar-fallidas
        {origen : ID de la extracción que contiene los trabajos fallidos}
        {--local= : ID del local a reprocesar}
        {--fecha= : Fecha exacta a reprocesar (Y-m-d)}';

    protected $description = 'Crea una extracción de corrección solo con ventas fallidas filtradas por local y fecha.';

    public function handle(): int
    {
        $origen = VentaExtraccion::findOrFail((int) $this->argument('origen'));
        $local = (string) $this->option('local');
        $fecha = (string) $this->option('fecha');

        if ($local === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            $this->error('Debes indicar --local y --fecha=Y-m-d.');

            return self::INVALID;
        }

        $trabajos = VentaExtraccionVenta::query()
            ->where('extraccion_id', $origen->id)
            ->where('estado', 'fallido')
            ->get()
            ->filter(function (VentaExtraccionVenta $trabajo) use ($local, $fecha): bool {
                $resumen = $trabajo->resumen ?? [];

                return (string) ($resumen['local_id'] ?? '') === $local
                    && str_starts_with((string) ($resumen['venta_fecha'] ?? ''), $fecha);
            })
            ->values();

        if ($trabajos->isEmpty()) {
            $this->warn('No hay ventas fallidas que coincidan con ese local y fecha.');

            return self::SUCCESS;
        }

        $correccion = DB::transaction(function () use ($origen, $trabajos, $local, $fecha): VentaExtraccion {
            $filtros = $origen->filtros ?? [];
            $filtros['locales'] = $local;
            $filtros['fechaInicio'] = $fecha;
            $filtros['fechaFin'] = $fecha;
            $filtros['reproceso_de_extraccion'] = $origen->id;

            $correccion = VentaExtraccion::create([
                'estado' => 'en_progreso',
                'filtros' => $filtros,
                'ventas_total_estimado' => $trabajos->count(),
                'paginas_total' => 0,
                'paginas_procesadas' => 0,
                'iniciado_at' => now(),
                'iniciado_por' => $origen->iniciado_por,
            ]);

            VentaExtraccionVenta::query()->insert($trabajos->map(fn (VentaExtraccionVenta $trabajo): array => [
                'extraccion_id' => $correccion->id,
                'venta_id' => $trabajo->venta_id,
                'estado' => 'pendiente',
                'resumen' => json_encode($trabajo->resumen, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ])->all());

            return $correccion;
        });

        VentaExtraccionVenta::query()
            ->where('extraccion_id', $correccion->id)
            ->orderBy('id')
            ->pluck('id')
            ->chunk(25)
            ->each(fn ($lote) => ProcesarLoteVentasDetalleJob::dispatch($correccion->id, $lote->all())->onQueue('ventas-details'));

        $this->info("Reproceso #{$correccion->id} iniciado con {$trabajos->count()} venta(s).");

        return self::SUCCESS;
    }
}
