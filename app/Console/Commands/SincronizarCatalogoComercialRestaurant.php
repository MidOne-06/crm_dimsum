<?php

namespace App\Console\Commands;

use App\Models\Venta;
use App\Services\CatalogoComercialRestaurantService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class SincronizarCatalogoComercialRestaurant extends Command
{
    protected $signature = 'ventas:sincronizar-catalogo-comercial
        {--desde= : Fecha inicial inclusiva (YYYY-MM-DD)}
        {--hasta= : Fecha final inclusiva (YYYY-MM-DD)}
        {--chunk=200 : Ventas por lote, entre 50 y 500}';

    protected $description = 'Normaliza productos y composiciones de Restaurant desde los detalles de ventas ya extraídos.';

    public function handle(CatalogoComercialRestaurantService $catalogo): int
    {
        try {
            $desde = $this->option('desde') ? Carbon::parse((string) $this->option('desde'))->startOfDay() : null;
            $hasta = $this->option('hasta') ? Carbon::parse((string) $this->option('hasta'))->endOfDay() : null;
        } catch (\Throwable) {
            $this->error('Las fechas deben tener el formato YYYY-MM-DD.');

            return self::FAILURE;
        }

        if ($desde && $hasta && $hasta->lessThan($desde)) {
            [$desde, $hasta] = [$hasta, $desde];
        }

        $chunk = min(500, max(50, (int) $this->option('chunk')));
        $totales = ['ventas' => 0, 'productos' => 0, 'composiciones' => 0, 'detalles' => 0];

        Venta::query()
            ->whereNotNull('raw')
            ->when($desde, fn (Builder $query): Builder => $query->where('venta_fecha', '>=', $desde))
            ->when($hasta, fn (Builder $query): Builder => $query->where('venta_fecha', '<=', $hasta))
            ->orderBy('venta_fecha')
            ->orderBy('venta_id')
            ->chunk($chunk, function ($ventas) use ($catalogo, &$totales): void {
                foreach ($ventas as $venta) {
                    $resultado = $catalogo->sincronizarVenta($venta);
                    $totales['ventas']++;
                    $totales['productos'] += $resultado['productos'];
                    $totales['composiciones'] += $resultado['composiciones'];
                    $totales['detalles'] += $resultado['detalles'];
                }

                $this->output->write('.');
            });

        $this->newLine();
        $this->info("Ventas: {$totales['ventas']} | Productos detectados: {$totales['productos']} | Composiciones Restaurant: {$totales['composiciones']} | Detalles vinculados: {$totales['detalles']}");

        return self::SUCCESS;
    }
}
