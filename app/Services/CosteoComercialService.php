<?php

namespace App\Services;

use App\Models\ProductoComercialCosto;
use App\Models\ProductoComercialRecetaManual;
use App\Models\ProductoComercialRestaurant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CosteoComercialService
{
    public function costoVigente(string $productoRestaurantId, Carbon|string|null $fecha = null): ?ProductoComercialCosto
    {
        $fecha = $fecha ? Carbon::parse($fecha)->toDateString() : now()->toDateString();

        return ProductoComercialCosto::query()
            ->where('producto_restaurant_id', $productoRestaurantId)
            ->whereDate('vigente_desde', '<=', $fecha)
            ->orderByDesc('vigente_desde')
            ->first();
    }

    public function recetaManualVigente(string $productoRestaurantId, Carbon|string|null $fecha = null): ?ProductoComercialRecetaManual
    {
        $fecha = $fecha ? Carbon::parse($fecha)->toDateString() : now()->toDateString();

        return ProductoComercialRecetaManual::query()
            ->with('componentes')
            ->where('producto_restaurant_id', $productoRestaurantId)
            ->whereDate('vigente_desde', '<=', $fecha)
            ->orderByDesc('vigente_desde')
            ->first();
    }

    /** @param array<string, mixed> $data */
    public function guardarCosto(ProductoComercialRestaurant $producto, array $data, ?User $usuario): ProductoComercialCosto
    {
        return DB::transaction(function () use ($producto, $data, $usuario): ProductoComercialCosto {
            return ProductoComercialCosto::query()->updateOrCreate(
                [
                    'producto_restaurant_id' => $producto->restaurant_producto_id,
                    'vigente_desde' => Carbon::parse((string) $data['vigente_desde'])->toDateString(),
                ],
                [
                    'costo_unitario' => round((float) $data['costo_unitario'], 9),
                    'observacion' => filled($data['observacion'] ?? null) ? trim((string) $data['observacion']) : null,
                    'registrado_por' => $usuario?->id,
                ],
            );
        });
    }

    /** @param array<string, mixed> $data */
    public function guardarRecetaManual(ProductoComercialRestaurant $producto, array $data, ?User $usuario): ProductoComercialRecetaManual
    {
        $componentes = collect((array) ($data['componentes'] ?? []))
            ->filter(fn (mixed $fila): bool => is_array($fila) && filled($fila['producto_restaurant_id'] ?? null))
            ->map(fn (array $fila): array => [
                'producto_restaurant_id' => trim((string) $fila['producto_restaurant_id']),
                'cantidad_por_producto' => round((float) ($fila['cantidad_por_producto'] ?? 0), 6),
            ])
            ->filter(fn (array $fila): bool => $fila['cantidad_por_producto'] > 0)
            ->groupBy('producto_restaurant_id')
            ->map(fn ($filas, string $id): array => [
                'producto_restaurant_id' => $id,
                'cantidad_por_producto' => round((float) $filas->sum('cantidad_por_producto'), 6),
            ])
            ->values();

        if ($componentes->isEmpty()) {
            throw ValidationException::withMessages(['componentes' => 'Registra al menos un componente con cantidad mayor a cero.']);
        }

        if ($componentes->contains('producto_restaurant_id', $producto->restaurant_producto_id)) {
            throw ValidationException::withMessages(['componentes' => 'Un producto no puede ser componente de su propia receta.']);
        }

        $ids = $componentes->pluck('producto_restaurant_id')->all();
        if (ProductoComercialRestaurant::query()->whereIn('restaurant_producto_id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['componentes' => 'Uno o más componentes ya no existen en el catálogo Restaurant.']);
        }

        return DB::transaction(function () use ($producto, $data, $usuario, $componentes): ProductoComercialRecetaManual {
            $receta = ProductoComercialRecetaManual::query()->updateOrCreate(
                [
                    'producto_restaurant_id' => $producto->restaurant_producto_id,
                    'vigente_desde' => Carbon::parse((string) $data['vigente_desde'])->toDateString(),
                ],
                [
                    'observacion' => filled($data['observacion'] ?? null) ? trim((string) $data['observacion']) : null,
                    'registrado_por' => $usuario?->id,
                ],
            );

            $receta->componentes()->delete();
            $receta->componentes()->createMany($componentes->map(fn (array $fila): array => [
                'componente_restaurant_producto_id' => $fila['producto_restaurant_id'],
                'cantidad_por_producto' => $fila['cantidad_por_producto'],
            ])->all());

            return $receta->load('componentes');
        });
    }
}
