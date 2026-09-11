<?php

namespace App\Console\Commands;

use App\Models\ProductoComercialRestaurant;
use App\Services\CosteoComercialService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CargarCostosComercialesIniciales extends Command
{
    protected $signature = 'ventas:cargar-costos-comerciales-iniciales {--vigente-desde=2026-09-01}';

    protected $description = 'Carga los costos iniciales validados y equivalencias de presentación del catálogo comercial.';

    /** @var array<string, float> */
    private const COSTOS = [
        '14' => 1.400573426, '17' => 1.467914077, '2' => 1.708498301,
        '5' => 2.073906864, '11' => 1.121249503, '8' => 1.927114945,
        '20' => 3.67353911, '30' => 1.541507852, '33' => 1.08276022,
        '36' => 0.982958931, '24' => 2.600570846, '27' => 1.255146373,
        '39' => 2.657597306, '0' => 2.657597306, '55' => 2.525019706,
        '54' => 3.025019706, '53' => 3.075019706, '56' => 2.725019706,
        '42' => 9.718401682, '116' => 9.718401682,
    ];

    /** @var array<string, array{base: string, cantidad: int}> */
    private const EQUIVALENCIAS = [
        '15' => ['base' => '14', 'cantidad' => 6], '16' => ['base' => '14', 'cantidad' => 12], '233' => ['base' => '14', 'cantidad' => 4], '110' => ['base' => '14', 'cantidad' => 6],
        '18' => ['base' => '17', 'cantidad' => 6], '19' => ['base' => '17', 'cantidad' => 12],
        '3' => ['base' => '2', 'cantidad' => 6], '6' => ['base' => '5', 'cantidad' => 6], '7' => ['base' => '5', 'cantidad' => 12],
        '12' => ['base' => '11', 'cantidad' => 6], '9' => ['base' => '8', 'cantidad' => 6], '10' => ['base' => '8', 'cantidad' => 12],
        '31' => ['base' => '30', 'cantidad' => 6], '32' => ['base' => '30', 'cantidad' => 12], '34' => ['base' => '33', 'cantidad' => 6], '35' => ['base' => '33', 'cantidad' => 12],
        '37' => ['base' => '36', 'cantidad' => 6], '38' => ['base' => '36', 'cantidad' => 12], '25' => ['base' => '24', 'cantidad' => 6], '26' => ['base' => '24', 'cantidad' => 12],
        '28' => ['base' => '27', 'cantidad' => 6], '29' => ['base' => '27', 'cantidad' => 12], '40' => ['base' => '39', 'cantidad' => 6], '41' => ['base' => '39', 'cantidad' => 12],
    ];

    public function handle(CosteoComercialService $costeo): int
    {
        $fecha = Carbon::parse((string) $this->option('vigente-desde'))->toDateString();
        $productos = ProductoComercialRestaurant::query()->whereIn('restaurant_producto_id', array_keys(self::COSTOS))->get()->keyBy('restaurant_producto_id');
        $faltantes = array_diff(array_keys(self::COSTOS), $productos->keys()->all());

        foreach ($productos as $id => $producto) {
            $costeo->guardarCosto($producto, ['vigente_desde' => $fecha, 'costo_unitario' => self::COSTOS[$id], 'observacion' => 'Carga inicial validada de costos de producción.'], null);
        }

        $recetas = 0;
        foreach (self::EQUIVALENCIAS as $id => $equivalencia) {
            $producto = ProductoComercialRestaurant::query()->where('restaurant_producto_id', $id)->first();
            if (! $producto || ! $productos->has($equivalencia['base'])) continue;
            $costeo->guardarRecetaManual($producto, [
                'vigente_desde' => $fecha,
                'observacion' => 'Equivalencia de presentación calculada desde el costo unitario base.',
                'componentes' => [[
                    'producto_restaurant_id' => $equivalencia['base'],
                    'cantidad_por_producto' => $equivalencia['cantidad'],
                ]],
            ], null);
            $recetas++;
        }

        $this->info('Costos base: '.$productos->count().' | Equivalencias: '.$recetas.' | Vigentes desde: '.$fecha);
        if ($faltantes !== []) $this->warn('IDs no encontrados: '.implode(', ', $faltantes));
        $this->warn('Pendiente de catálogo Restaurant: Enrollado vegetariano. No se cargó un costo sin coincidencia real.');

        return self::SUCCESS;
    }
}
