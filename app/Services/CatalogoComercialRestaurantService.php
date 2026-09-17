<?php

namespace App\Services;

use App\Models\ProductoComercialComponente;
use App\Models\ProductoComercialComposicion;
use App\Models\ProductoComercialRestaurant;
use App\Models\Venta;
use App\Models\VentaComponenteCombo;
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
     * Toma los locks del catálogo para un lote completo ANTES de persistirlo.
     *
     * `ProcesarLoteVentasDetalleJob` guarda varias ventas en una única
     * transacción. Bloquear cada venta de forma aislada era insuficiente: el
     * lote A podía tomar producto X y luego esperar Y mientras el lote B ya
     * tenía Y y esperaba X. Al unir y ordenar los productos de todo el lote,
     * todos los workers adquieren los mismos locks en el mismo orden.
     *
     * @param  array<int, array{payload: array<string, mixed>}>  $catalogos
     */
    public function bloquearCatalogos(array $catalogos): void
    {
        $productoIds = [];

        foreach ($catalogos as $catalogo) {
            $lineas = $catalogo['payload']['detalleventaList'] ?? [];
            if (is_array($lineas)) {
                $productoIds = [...$productoIds, ...$this->productoIdsDeLineas($lineas)];
            }
        }

        $this->bloquearProductoIds($productoIds);
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
            // Varios workers pueden observar el mismo producto Restaurant en
            // ventas distintas. Sin un orden global de locks, dos lotes que
            // actualizan productos/composiciones compartidos forman ciclos de
            // bloqueo en PostgreSQL. Se bloquean todos los IDs de la venta en
            // orden estable y el lock se libera automáticamente al cerrar la
            // transacción externa que también persiste la venta y su detalle.
            $this->bloquearProductosDeLineas($lineas);

            // La extracción puede reintentarse o sincronizarse nuevamente.
            // Se reemplaza únicamente el detalle derivado de esta venta para
            // que el hecho conserve exactamente el payload Restaurant vigente
            // y no duplique componentes.
            VentaComponenteCombo::query()->where('venta_id', $ventaId)->delete();

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

                $cantidadPadre = max(0.0, $this->numero($linea['detalleventa_cantidad'] ?? $linea['item_cantidad'] ?? 0));

                $actualizado = VentaDetalle::query()
                    ->where('venta_id', $ventaId)
                    ->where('item_id', $detalleId)
                    ->update([
                        'producto_restaurant_id' => $productoId,
                        'composicion_comercial_id' => $composicionId,
                        'es_producto_compuesto_restaurant' => $componentes !== [],
                        'componentes_payload_count' => count($componentes),
                        'updated_at' => now(),
                    ]);

                $resultado['detalles'] += $actualizado;

                if ($componentes !== [] && $composicionId !== null) {
                    $ahora = now();
                    $filasComponentes = collect($componentes)
                        ->map(fn (array $componente): array => [
                            'venta_id' => $ventaId,
                            'detalle_venta_item_id' => $detalleId,
                            'producto_compuesto_restaurant_id' => $productoId,
                            'producto_restaurant_id' => $componente['producto_id'],
                            'descripcion' => $componente['nombre'] ?? $componente['descripcion_venta'],
                            'unidad' => $componente['unidad'],
                            'cantidad_por_combo' => $componente['cantidad_por_producto'],
                            'cantidad_total' => $componente['cantidad_por_producto'] * $cantidadPadre,
                            'composicion_comercial_id' => $composicionId,
                            'origen' => 'payload_restaurant',
                            'created_at' => $ahora,
                            'updated_at' => $ahora,
                        ])
                        ->all();

                    VentaComponenteCombo::query()->upsert(
                        $filasComponentes,
                        ['venta_id', 'detalle_venta_item_id', 'producto_restaurant_id'],
                        ['producto_compuesto_restaurant_id', 'descripcion', 'unidad', 'cantidad_por_combo', 'cantidad_total', 'composicion_comercial_id', 'origen', 'updated_at'],
                    );
                }
            }

            return $resultado;
        });
    }

    /** @param array<int, mixed> $lineas */
    private function bloquearProductosDeLineas(array $lineas): void
    {
        $this->bloquearProductoIds($this->productoIdsDeLineas($lineas));
    }

    /** @param array<int, mixed> $lineas @return array<int, string> */
    private function productoIdsDeLineas(array $lineas): array
    {
        return collect($lineas)
            ->filter(fn (mixed $linea): bool => is_array($linea))
            ->flatMap(function (array $linea): array {
                $ids = [$this->productoId($linea)];

                foreach ($this->componentesPorProducto($linea) as $componente) {
                    $ids[] = $componente['producto_id'];
                }

                return $ids;
            })
            ->filter(fn (mixed $id): bool => $id !== null)
            ->map(fn (mixed $id): string => (string) $id)
            ->unique()
            ->sort(SORT_NATURAL)
            ->values()
            ->all();
    }

    /** @param array<int, string> $productoIds */
    private function bloquearProductoIds(array $productoIds): void
    {
        foreach (collect($productoIds)->unique()->sort(SORT_NATURAL)->values() as $productoId) {
            // hashtext únicamente define el candado de sesión; los valores se
            // enlazan como parámetro y una colisión solo serializa de más, sin
            // mezclar ni modificar datos de productos distintos.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["catalogo-comercial:{$productoId}"]);
        }
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
