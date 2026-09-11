<?php

namespace App\Services;

use App\Models\ProductoComercialComponente;
use App\Models\ProductoComercialComposicion;
use App\Models\ProductoComercialRestaurant;
use App\Models\Venta;
use App\Models\VentaDetalle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Normaliza el catálogo que Restaurant devuelve DENTRO de cada detalle de
 * venta. No consulta ni escribe Restaurant: conserva la composición exacta
 * observada en ventas para que el costo posterior sea trazable por fecha.
 */
class CatalogoComercialRestaurantService
{
    /** @return array{productos: int, composiciones: int, detalles: int} */
    public function sincronizarVenta(Venta $venta): array
    {
        return $this->sincronizarPayload(
            (string) $venta->venta_id,
            is_array($venta->raw) ? $venta->raw : [],
            $venta->venta_fecha,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{productos: int, composiciones: int, detalles: int}
     */
    public function sincronizarPayload(string $ventaId, array $payload, Carbon|string|null $fechaVenta = null): array
    {
        $lineas = $payload['detalleventaList'] ?? [];
        if (! is_array($lineas)) {
            return ['productos' => 0, 'composiciones' => 0, 'detalles' => 0];
        }

        $fecha = $fechaVenta ? Carbon::parse($fechaVenta) : now();

        return DB::transaction(function () use ($ventaId, $lineas, $fecha): array {
            $resultado = ['productos' => 0, 'composiciones' => 0, 'detalles' => 0];

            foreach ($lineas as $linea) {
                if (! is_array($linea)) {
                    continue;
                }

                $productoId = $this->productoId($linea);
                $detalleId = $this->string($linea['detalleventa_id'] ?? null);
                if ($productoId === null || $detalleId === null) {
                    continue;
                }

                $componentes = $this->componentesPorProducto($linea);
                $esCombo = $this->boolean($linea['detalleventa_escombo'] ?? false);
                $this->guardarProducto($productoId, $linea, $esCombo, $componentes !== []);
                $resultado['productos']++;

                foreach ($componentes as $componente) {
                    $this->guardarProducto($componente['producto_id'], $componente['linea'], false, false);
                    $resultado['productos']++;
                }

                $composicionId = null;
                if ($componentes !== []) {
                    $composicionId = $this->guardarComposicion($productoId, $componentes, $ventaId, $fecha);
                    $resultado['composiciones']++;
                }

                $actualizado = VentaDetalle::query()
                    ->where('venta_id', $ventaId)
                    ->where('item_id', $detalleId)
                    ->update([
                        'producto_restaurant_id' => $productoId,
                        'composicion_comercial_id' => $composicionId,
                        'updated_at' => now(),
                    ]);

                $resultado['detalles'] += $actualizado;
            }

            return $resultado;
        });
    }

    /** @param array<string, mixed> $linea */
    private function guardarProducto(string $productoId, array $linea, bool $esCombo, bool $tieneComposicion): void
    {
        $now = now();
        $datos = [
            'nombre' => $this->string($linea['producto_descripcion'] ?? null) ?? $this->string($linea['detalleventa_productodescripcion'] ?? null),
            'descripcion_venta' => $this->string($linea['detalleventa_productodescripcion'] ?? null),
            'codigo' => $this->string($linea['producto_codigointerno'] ?? null) ?? $this->string($linea['producto_codigo'] ?? null),
            'unidad' => $this->string($linea['unidadmedida_descripcion_unidad'] ?? null) ?? $this->string($linea['unidadmedida_descripcion'] ?? null),
            'es_combo' => $esCombo,
            'lleva_ingredientes' => $this->boolean($linea['producto_llevaingredientes'] ?? false),
            'tiene_composicion_restaurant' => $tieneComposicion,
            // Se guarda solo el conjunto de campos útil para auditoría; el
            // payload completo de la venta ya permanece en ventas.raw.
            'fuente_restaurant' => $this->resumenProducto($linea),
            'sincronizado_en' => $now,
            'updated_at' => $now,
        ];

        $producto = ProductoComercialRestaurant::query()->firstOrNew(['restaurant_producto_id' => $productoId]);
        // Una posterior venta simple no debe borrar el hecho ya observado de
        // que el producto puede ser un combo o tener composición Restaurant.
        $producto->fill([
            ...$datos,
            'es_combo' => (bool) $producto->es_combo || $esCombo,
            'tiene_composicion_restaurant' => (bool) $producto->tiene_composicion_restaurant || $tieneComposicion,
        ]);
        $producto->save();
    }

    /**
     * @param  array<int, array<string, mixed>>  $componentes
     */
    private function guardarComposicion(string $productoId, array $componentes, string $ventaId, Carbon $fecha): int
    {
        $huella = hash('sha256', json_encode([
            'producto' => $productoId,
            'componentes' => collect($componentes)
                ->map(fn (array $componente): array => [
                    'producto' => $componente['producto_id'],
                    'cantidad' => number_format((float) $componente['cantidad_por_producto'], 6, '.', ''),
                    'unidad' => $componente['unidad'],
                ])
                ->sortBy(fn (array $componente): string => $componente['producto'].'|'.$componente['unidad'])
                ->values()
                ->all(),
        ], JSON_THROW_ON_ERROR));

        $now = now();
        $composicion = ProductoComercialComposicion::query()->firstOrCreate(
            ['producto_restaurant_id' => $productoId, 'huella' => $huella],
            [
                'primera_venta_en' => $fecha,
                'ultima_venta_en' => $fecha,
                'fuente_restaurant' => ['venta_id_origen' => $ventaId, 'origen' => 'detalleventaList.productocomboList'],
            ],
        );

        $primeraVenta = $composicion->primera_venta_en;
        $ultimaVenta = $composicion->ultima_venta_en;
        $composicion->update([
            'primera_venta_en' => $primeraVenta && $primeraVenta->lessThan($fecha) ? $primeraVenta : $fecha,
            'ultima_venta_en' => $ultimaVenta && $ultimaVenta->greaterThan($fecha) ? $ultimaVenta : $fecha,
            'updated_at' => $now,
        ]);

        foreach ($componentes as $componente) {
            ProductoComercialComponente::query()->updateOrCreate(
                [
                    'composicion_id' => $composicion->id,
                    'componente_restaurant_producto_id' => $componente['producto_id'],
                ],
                [
                    'nombre' => $componente['nombre'],
                    'descripcion_venta' => $componente['descripcion_venta'],
                    'codigo' => $componente['codigo'],
                    'unidad' => $componente['unidad'],
                    'cantidad_por_producto' => $componente['cantidad_por_producto'],
                    'fuente_restaurant' => $componente['fuente_restaurant'],
                ],
            );
        }

        return $composicion->id;
    }

    /**
     * Restaurant expande el combo por la cantidad vendida. Se normaliza a
     * "cantidad por una unidad del producto padre": 9 combos con 18 Siu Mai
     * se guardan como 2 Siu Mai por Combo 1, no como una receta de 18.
     *
     * @param  array<string, mixed>  $linea
     * @return array<int, array{producto_id: string, nombre: ?string, descripcion_venta: ?string, codigo: ?string, unidad: ?string, cantidad_por_producto: float, fuente_restaurant: array<string, mixed>, linea: array<string, mixed>}>
     */
    private function componentesPorProducto(array $linea): array
    {
        $raw = $linea['productocomboList'] ?? [];
        if (! is_array($raw) || $raw === []) {
            return [];
        }

        $cantidadPadre = max(1.0, $this->numero($linea['detalleventa_cantidad'] ?? 1));

        return collect($raw)
            ->filter(fn (mixed $componente): bool => is_array($componente))
            ->map(function (array $componente) use ($cantidadPadre): ?array {
                $productoId = $this->productoId($componente);
                if ($productoId === null) {
                    return null;
                }

                $cantidadTotal = $this->numero($componente['item_cantidad'] ?? $componente['detalleventa_cantidad'] ?? 0);
                if ($cantidadTotal <= 0) {
                    return null;
                }

                return [
                    'producto_id' => $productoId,
                    'nombre' => $this->string($componente['producto_descripcion'] ?? null),
                    'descripcion_venta' => $this->string($componente['detalleventa_productodescripcion'] ?? null),
                    'codigo' => $this->string($componente['producto_codigointerno'] ?? null) ?? $this->string($componente['producto_codigo'] ?? null),
                    'unidad' => $this->string($componente['unidadmedida_descripcion_unidad'] ?? null) ?? $this->string($componente['unidadmedida_descripcion'] ?? null),
                    'cantidad_por_producto' => $cantidadTotal / $cantidadPadre,
                    'fuente_restaurant' => $this->resumenProducto($componente),
                    'linea' => $componente,
                ];
            })
            ->filter()
            ->groupBy('producto_id')
            ->map(function ($items): array {
                $primero = $items->first();
                $primero['cantidad_por_producto'] = $items->sum('cantidad_por_producto');

                return $primero;
            })
            ->sortKeys()
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $linea */
    private function productoId(array $linea): ?string
    {
        return $this->string($linea['detalleventa_productoid'] ?? null)
            ?? $this->string($linea['producto_id'] ?? null)
            ?? $this->string($linea['item_id'] ?? null);
    }

    /** @param array<string, mixed> $linea @return array<string, mixed> */
    private function resumenProducto(array $linea): array
    {
        return [
            'producto_id' => $this->productoId($linea),
            'descripcion' => $this->string($linea['producto_descripcion'] ?? null),
            'descripcion_venta' => $this->string($linea['detalleventa_productodescripcion'] ?? null),
            'codigo' => $this->string($linea['producto_codigointerno'] ?? null) ?? $this->string($linea['producto_codigo'] ?? null),
            'cantidad' => $this->numero($linea['detalleventa_cantidad'] ?? $linea['item_cantidad'] ?? 0),
            'unidad' => $this->string($linea['unidadmedida_descripcion_unidad'] ?? null) ?? $this->string($linea['unidadmedida_descripcion'] ?? null),
            'es_combo' => $this->boolean($linea['detalleventa_escombo'] ?? false),
            'lleva_ingredientes' => $this->boolean($linea['producto_llevaingredientes'] ?? false),
        ];
    }

    private function string(mixed $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' || $valor === '-' ? null : $valor;
    }

    private function numero(mixed $valor): float
    {
        return is_numeric($valor) ? (float) $valor : 0.0;
    }

    private function boolean(mixed $valor): bool
    {
        return in_array($valor, [true, 1, '1', 'true', 'TRUE'], true);
    }
}
