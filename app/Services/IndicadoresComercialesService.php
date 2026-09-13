<?php

namespace App\Services;

use App\Models\CuotaVentaRestaurant;
use App\Models\ProductoComercialComponente;
use App\Models\ProductoComercialComposicion;
use App\Models\ProductoComercialCosto;
use App\Models\ProductoComercialRecetaManual;
use App\Models\ProductoComercialRecetaManualComponente;
use App\Models\ProductoComercialRestaurant;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaDetalle;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class IndicadoresComercialesService
{
    /** @return array<string, string> */
    public function opcionesUnidades(?User $usuario = null, ?Carbon $periodo = null): array
    {
        return $this->restaurantPermitidas($usuario, $periodo ?? now())
            ->sortBy('local')
            ->mapWithKeys(fn (CuotaVentaRestaurant $cuota): array => ["restaurant:{$cuota->codigo}" => "{$cuota->codigo} · {$cuota->local}"])
            ->all();
    }

    /** @param array<int, string> $seleccion @return array<string, mixed> */
    public function tablero(Carbon $desde, Carbon $hasta, array $seleccion = [], ?User $usuario = null): array
    {
        [$desde, $hasta] = $this->ordenarRango($desde, $hasta);
        $scope = $this->scope($seleccion, $usuario, $hasta);

        return [
            'periodo' => $this->metricas($scope, $desde, $hasta),
            'ytd' => $this->metricas($scope, $hasta->copy()->startOfYear(), $hasta),
            'ranking_locales' => $this->rankingLocales($scope, $desde, $hasta),
            'ranking_productos' => $this->rankingProductos($scope, $desde, $hasta),
            'tendencia' => $this->tendencia($scope, $desde, $hasta),
            'unidades' => $scope['unidades']->count(),
        ];
    }

    /** @param array<int, string> $seleccion @return array{restaurant: Collection<int, CuotaVentaRestaurant>, unidades: Collection<int, array{tipo: string, id: string, codigo: string, nombre: string, local_id: ?string}>} */
    private function scope(array $seleccion, ?User $usuario, Carbon $periodo): array
    {
        $restaurant = $this->restaurantPermitidas($usuario, $periodo);
        // El tablero comercial se limita deliberadamente a locales que
        // venden en Restaurant. Los canales externos se conservan en su
        // módulo, pero no alteran ventas, cuotas, TKP, MB ni rankings aquí.
        $seleccion = array_values(array_filter($seleccion, fn (mixed $valor): bool => is_string($valor) && str_starts_with($valor, 'restaurant:')));

        $restaurantCodigos = collect($seleccion)
            ->map(fn (string $valor): string => str_replace('restaurant:', '', $valor))
            ->values();

        if ($seleccion !== []) {
            $restaurant = $restaurant->whereIn('codigo', $restaurantCodigos->all());
        }

        $unidades = $restaurant->map(fn (CuotaVentaRestaurant $cuota): array => [
            'tipo' => 'restaurant',
            'id' => $cuota->codigo,
            'codigo' => $cuota->codigo,
            'nombre' => $cuota->local,
            'local_id' => $cuota->local_id,
        ])->values();

        return compact('restaurant', 'unidades');
    }

    /** @param array{restaurant: Collection<int, CuotaVentaRestaurant>, unidades: Collection<int, array{tipo: string, id: string, codigo: string, nombre: string, local_id: ?string}>} $scope @return array<string, mixed> */
    private function metricas(array $scope, Carbon $desde, Carbon $hasta): array
    {
        $ventas = $this->ventasPorUnidad($scope, $desde, $hasta);
        $cuotas = $this->cuotasPorUnidad($scope, $desde, $hasta);
        $sinIgv = (float) $ventas->sum('sin_igv');
        $conIgv = (float) $ventas->sum('con_igv');
        $tickets = (int) $ventas->sum('tickets');
        $cuota = $cuotas['completa'] ? (float) collect($cuotas['por_unidad'])->sum('sin_igv') : null;
        $costeoRestaurant = $this->costeoRestaurant($scope, $desde, $hasta);
        $importeConCosto = $costeoRestaurant['importe_con_costo'];
        $costo = $costeoRestaurant['costo'];
        $cobertura = $sinIgv > 0 ? min(100, ($importeConCosto / $sinIgv) * 100) : 100;

        return [
            'sin_igv' => $sinIgv,
            'con_igv' => $conIgv,
            'tickets' => $tickets,
            'cuota_sin_igv' => $cuota,
            'avance' => $cuota !== null && $cuota > 0 ? ($sinIgv / $cuota) * 100 : null,
            'tkp' => $tickets > 0 ? $conIgv / $tickets : null,
            // MB solamente se publica cuando cada venta tiene costo trazable.
            // Ante cobertura parcial se informa la cobertura, no un margen engañoso.
            'mb' => $sinIgv > 0 && $cobertura >= 99.999 ? (($sinIgv - $costo) / $sinIgv) * 100 : null,
            'cobertura_costos' => $cobertura,
            'importe_sin_costo' => max(0, $sinIgv - $importeConCosto),
            'cuotas_cargadas' => $cuotas['cargadas'],
            'cuotas_requeridas' => $cuotas['requeridas'],
        ];
    }

    /**
     * Costea las líneas Restaurant por su producto y receta real observada.
     *
     * El agrupamiento conserva fecha, producto y composición: así respeta la
     * vigencia de costos y no mezcla variantes de un combo que Restaurant
     * haya vendido con recetas distintas.
     *
     * @param array{restaurant: Collection<int, CuotaVentaRestaurant>, unidades: Collection<int, array{tipo: string, id: string, codigo: string, nombre: string, local_id: ?string}>} $scope
     * @return array{importe_con_costo: float, costo: float}
     */
    private function costeoRestaurant(array $scope, Carbon $desde, Carbon $hasta): array
    {
        $locales = $scope['restaurant']->pluck('local_id')->filter()->values()->all();
        if ($locales === []) {
            return ['importe_con_costo' => 0.0, 'costo' => 0.0];
        }

        $lineas = VentaDetalle::query()
            ->join('ventas', 'ventas.venta_id', '=', 'venta_detalles.venta_id')
            ->where('ventas.estado', 'Activo')
            ->whereIn('ventas.local_id', $locales)
            ->whereBetween('ventas.venta_fecha', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()])
            ->selectRaw('venta_detalles.producto_restaurant_id, venta_detalles.composicion_comercial_id, DATE(ventas.venta_fecha) as fecha, COALESCE(SUM(venta_detalles.cantidad), 0) as cantidad, COALESCE(SUM(venta_detalles.importe), 0) as importe')
            ->groupBy('venta_detalles.producto_restaurant_id', 'venta_detalles.composicion_comercial_id')
            ->groupByRaw('DATE(ventas.venta_fecha)')
            ->get();

        if ($lineas->isEmpty()) {
            return ['importe_con_costo' => 0.0, 'costo' => 0.0];
        }

        $composicionIds = $lineas->pluck('composicion_comercial_id')->filter()->map(fn (mixed $id): int => (int) $id)->unique()->values();
        $composiciones = ProductoComercialComposicion::query()
            ->whereIn('id', $composicionIds->all())
            ->get()
            ->keyBy('id');
        $componentesComposicion = ProductoComercialComponente::query()
            ->whereIn('composicion_id', $composicionIds->all())
            ->get()
            ->groupBy('composicion_id');
        $costos = ProductoComercialCosto::query()
            ->orderByDesc('vigente_desde')
            ->get()
            ->groupBy('producto_restaurant_id');
        $recetas = ProductoComercialRecetaManual::query()
            ->orderByDesc('vigente_desde')
            ->get()
            ->groupBy('producto_restaurant_id');
        $recetaIds = $recetas->flatten(1)->pluck('id')->all();
        $componentesReceta = ProductoComercialRecetaManualComponente::query()
            ->whereIn('receta_manual_id', $recetaIds)
            ->get()
            ->groupBy('receta_manual_id');
        $productos = ProductoComercialRestaurant::query()
            ->get()
            ->keyBy('restaurant_producto_id');

        $importeConCosto = 0.0;
        $costoTotal = 0.0;
        foreach ($lineas as $linea) {
            $fecha = Carbon::parse((string) $linea->fecha)->toDateString();
            $productoId = filled($linea->producto_restaurant_id) ? (string) $linea->producto_restaurant_id : null;
            $costoUnitario = $this->costoLineaRestaurant(
                $productoId,
                $linea->composicion_comercial_id ? (int) $linea->composicion_comercial_id : null,
                $fecha,
                $composiciones,
                $componentesComposicion,
                $costos,
                $recetas,
                $componentesReceta,
                $productos,
            );

            if ($costoUnitario === null) {
                continue;
            }

            $importeConCosto += (float) $linea->importe;
            $costoTotal += (float) $linea->cantidad * $costoUnitario;
        }

        return ['importe_con_costo' => $importeConCosto, 'costo' => $costoTotal];
    }

    /** @param Collection<int, ProductoComercialComposicion> $composiciones @param Collection<int, Collection<int, ProductoComercialComponente>> $componentesComposicion @param Collection<string, Collection<int, ProductoComercialCosto>> $costos @param Collection<string, Collection<int, ProductoComercialRecetaManual>> $recetas @param Collection<int, Collection<int, ProductoComercialRecetaManualComponente>> $componentesReceta @param Collection<string, ProductoComercialRestaurant> $productos */
    private function costoLineaRestaurant(?string $productoId, ?int $composicionId, string $fecha, Collection $composiciones, Collection $componentesComposicion, Collection $costos, Collection $recetas, Collection $componentesReceta, Collection $productos): ?float
    {
        if ($composicionId !== null && $composiciones->has($composicionId)) {
            $componentes = $componentesComposicion->get($composicionId, collect());
            if ($componentes->isEmpty()) {
                return null;
            }

            $total = 0.0;
            foreach ($componentes as $componente) {
                $costo = $this->costoProductoRestaurant((string) $componente->componente_restaurant_producto_id, $fecha, $costos, $recetas, $componentesReceta, $productos, []);
                if ($costo === null) {
                    return null;
                }
                $total += $costo * (float) $componente->cantidad_por_producto;
            }

            return $total;
        }

        return $productoId === null ? null : $this->costoProductoRestaurant($productoId, $fecha, $costos, $recetas, $componentesReceta, $productos, []);
    }

    /** @param Collection<string, Collection<int, ProductoComercialCosto>> $costos @param Collection<string, Collection<int, ProductoComercialRecetaManual>> $recetas @param Collection<int, Collection<int, ProductoComercialRecetaManualComponente>> $componentesReceta @param Collection<string, ProductoComercialRestaurant> $productos @param array<int, string> $camino */
    private function costoProductoRestaurant(string $productoId, string $fecha, Collection $costos, Collection $recetas, Collection $componentesReceta, Collection $productos, array $camino): ?float
    {
        if (in_array($productoId, $camino, true)) {
            return null;
        }
        $camino[] = $productoId;

        $costo = $costos->get($productoId, collect())->first(fn (ProductoComercialCosto $fila): bool => $fila->vigente_desde->toDateString() <= $fecha);
        if ($costo !== null) {
            return (float) $costo->costo_unitario;
        }

        $receta = $recetas->get($productoId, collect())->first(fn (ProductoComercialRecetaManual $fila): bool => $fila->vigente_desde->toDateString() <= $fecha);
        if ($receta === null) {
            return $this->costoPresentacionDerivada($productoId, $fecha, $costos, $recetas, $componentesReceta, $productos, $camino);
        }

        $componentes = $componentesReceta->get($receta->id, collect());
        if ($componentes->isEmpty()) {
            return null;
        }

        $total = 0.0;
        foreach ($componentes as $componente) {
            $costoComponente = $this->costoProductoRestaurant((string) $componente->componente_restaurant_producto_id, $fecha, $costos, $recetas, $componentesReceta, $productos, $camino);
            if ($costoComponente === null) {
                return null;
            }
            $total += $costoComponente * (float) $componente->cantidad_por_producto;
        }

        return $total;
    }

    /**
     * Restaurant identifica cada presentación con un producto distinto. Las
     * presentaciones que declaran una fracción exacta de docena son, sin
     * embargo, el mismo producto unitario multiplicado por su cantidad. Esta
     * regla conserva el costo base vigente y evita duplicar costos manuales.
     *
     * No se aplica a presentaciones ambiguas (ciento, gramos, botellas, etc.):
     * esas continúan requiriendo un costo o receta explícita.
     *
     * @param Collection<string, Collection<int, ProductoComercialCosto>> $costos
     * @param Collection<string, Collection<int, ProductoComercialRecetaManual>> $recetas
     * @param Collection<int, Collection<int, ProductoComercialRecetaManualComponente>> $componentesReceta
     * @param Collection<string, ProductoComercialRestaurant> $productos
     * @param array<int, string> $camino
     */
    private function costoPresentacionDerivada(string $productoId, string $fecha, Collection $costos, Collection $recetas, Collection $componentesReceta, Collection $productos, array $camino): ?float
    {
        $producto = $productos->get($productoId);
        if (! $producto) {
            return null;
        }

        $presentacion = $this->presentacionPorDocena($this->descripcionProducto($producto));
        if ($presentacion === null) {
            return null;
        }

        [$base, $multiplicador] = $presentacion;
        $baseId = $productos
            ->reject(fn (ProductoComercialRestaurant $candidato): bool => $candidato->restaurant_producto_id === $productoId)
            ->first(function (ProductoComercialRestaurant $candidato) use ($base): bool {
                return $this->presentacionPorDocena($this->descripcionProducto($candidato)) === null
                    && $this->claveProductoUnitario($this->descripcionProducto($candidato)) === $base;
            })
            ?->restaurant_producto_id;

        if (! $baseId) {
            return null;
        }

        $costoBase = $this->costoProductoRestaurant((string) $baseId, $fecha, $costos, $recetas, $componentesReceta, $productos, $camino);

        return $costoBase === null ? null : $costoBase * $multiplicador;
    }

    /** @return array{0: string, 1: float}|null */
    private function presentacionPorDocena(string $descripcion): ?array
    {
        $texto = mb_strtoupper(\Illuminate\Support\Str::ascii($descripcion));
        $reglas = [
            '/\b1\s*\/\s*2\s+DOCENA\b|\bMEDIA\s+DOCENA\b/' => 6.0,
            '/\b1\s*\/\s*3\s+DOCENA\b/' => 4.0,
            '/\bDOCENA\b/' => 12.0,
        ];

        foreach ($reglas as $patron => $multiplicador) {
            if (preg_match($patron, $texto) !== 1) {
                continue;
            }

            $base = preg_replace($patron, '', $texto);

            return [$this->claveProductoUnitario((string) $base), $multiplicador];
        }

        return null;
    }

    private function descripcionProducto(ProductoComercialRestaurant $producto): string
    {
        return (string) ($producto->descripcion_venta ?: $producto->nombre ?: '');
    }

    private function claveProductoUnitario(string $descripcion): string
    {
        $texto = mb_strtoupper(\Illuminate\Support\Str::ascii($descripcion));
        $texto = preg_replace('/\s*[:\-]\s*(?:UND|UN|UNIDAD)\b.*$/', '', $texto);
        $texto = preg_replace('/\s*[:\-]\s*$/', '', (string) $texto);
        $texto = preg_replace('/\s+/', ' ', (string) $texto);

        return trim((string) $texto);
    }

    /** @param array{restaurant: Collection<int, CuotaVentaRestaurant>, unidades: Collection<int, array{tipo: string, id: string, codigo: string, nombre: string, local_id: ?string}>} $scope @return Collection<int, array{tipo: string, id: string, sin_igv: float, con_igv: float, tickets: int}> */
    private function ventasPorUnidad(array $scope, Carbon $desde, Carbon $hasta): Collection
    {
        $restaurantIds = $scope['restaurant']->pluck('local_id')->filter()->values()->all();
        $inicio = $desde->copy()->startOfDay();
        $fin = $hasta->copy()->endOfDay();

        $restaurant = Venta::query()
            ->where('estado', 'Activo')
            ->whereIn('local_id', $restaurantIds)
            ->whereBetween('venta_fecha', [$inicio, $fin])
            ->selectRaw('local_id, COALESCE(SUM(subtotal), 0) as sin_igv, COALESCE(SUM(total), 0) as con_igv, COUNT(*) as tickets')
            ->groupBy('local_id')
            ->get()
            ->mapWithKeys(fn (Venta $venta): array => [(string) $venta->local_id => [
                'sin_igv' => (float) $venta->sin_igv,
                'con_igv' => (float) $venta->con_igv,
                'tickets' => (int) $venta->tickets,
            ]]);

        return $scope['unidades']->map(function (array $unidad) use ($restaurant): array {
            $suma = $restaurant->get((string) $unidad['local_id']) ?? [];

            return [
                'tipo' => $unidad['tipo'],
                'id' => $unidad['id'],
                'sin_igv' => (float) ($suma['sin_igv'] ?? 0),
                'con_igv' => (float) ($suma['con_igv'] ?? 0),
                'tickets' => (int) ($suma['tickets'] ?? 0),
            ];
        });
    }

    /** @param array{restaurant: Collection<int, CuotaVentaRestaurant>, unidades: Collection<int, array{tipo: string, id: string, codigo: string, nombre: string, local_id: ?string}>} $scope @return array{por_unidad: array<string, array{sin_igv: float, con_igv: float, completa: bool}>, cargadas: int, requeridas: int, completa: bool} */
    private function cuotasPorUnidad(array $scope, Carbon $desde, Carbon $hasta): array
    {
        $periodos = collect(CarbonPeriod::create($desde->copy()->startOfMonth(), '1 month', $hasta->copy()->startOfMonth()))
            ->map(fn (Carbon $fecha): string => $fecha->toDateString())
            ->values();
        $restaurantCodigos = $scope['restaurant']->pluck('codigo')->all();
        $restaurant = CuotaVentaRestaurant::query()
            ->whereIn('codigo', $restaurantCodigos)
            ->whereIn('periodo', $periodos->all())
            ->get()
            ->groupBy('codigo');
        $porUnidad = [];
        foreach ($scope['unidades'] as $unidad) {
            $filas = $restaurant->get($unidad['id']) ?? collect();
            $completa = $filas->count() === $periodos->count();
            $porUnidad["{$unidad['tipo']}:{$unidad['id']}"] = [
                'sin_igv' => (float) $filas->sum('cuota_sin_igv'),
                'con_igv' => (float) $filas->sum('cuota_con_igv'),
                'completa' => $completa,
            ];
        }

        $cargadas = collect($porUnidad)->sum(fn (array $cuota): int => $cuota['completa'] ? 1 : 0);
        $requeridas = $scope['unidades']->count();

        return [
            'por_unidad' => $porUnidad,
            'cargadas' => $cargadas,
            'requeridas' => $requeridas,
            'completa' => $cargadas === $requeridas,
        ];
    }

    /** @param array{restaurant: Collection<int, CuotaVentaRestaurant>, unidades: Collection<int, array{tipo: string, id: string, codigo: string, nombre: string, local_id: ?string}>} $scope @return Collection<int, array<string, mixed>> */
    private function rankingLocales(array $scope, Carbon $desde, Carbon $hasta): Collection
    {
        $ventas = $this->ventasPorUnidad($scope, $desde, $hasta)->keyBy(fn (array $venta): string => "{$venta['tipo']}:{$venta['id']}");
        $cuotas = $this->cuotasPorUnidad($scope, $desde, $hasta)['por_unidad'];

        return $scope['unidades']->map(function (array $unidad) use ($ventas, $cuotas): array {
            $key = "{$unidad['tipo']}:{$unidad['id']}";
            $venta = $ventas->get($key, ['sin_igv' => 0]);
            $cuota = $cuotas[$key] ?? ['sin_igv' => 0, 'completa' => false];
            $montoCuota = $cuota['completa'] ? (float) $cuota['sin_igv'] : null;

            return [
                'codigo' => $unidad['codigo'],
                'nombre' => $unidad['nombre'],
                'sin_igv' => (float) $venta['sin_igv'],
                'cuota_sin_igv' => $montoCuota,
                'avance' => $montoCuota !== null && $montoCuota > 0 ? ((float) $venta['sin_igv'] / $montoCuota) * 100 : null,
            ];
        })->sortByDesc('sin_igv')->values();
    }

    /** @param array{restaurant: Collection<int, CuotaVentaRestaurant>, unidades: Collection<int, array{tipo: string, id: string, codigo: string, nombre: string, local_id: ?string}>} $scope @return Collection<int, array<string, mixed>> */
    private function rankingProductos(array $scope, Carbon $desde, Carbon $hasta): Collection
    {
        $locales = $scope['restaurant']->pluck('local_id')->filter()->values()->all();
        if ($locales === []) {
            return collect();
        }

        $rows = VentaDetalle::query()
            ->join('ventas', 'ventas.venta_id', '=', 'venta_detalles.venta_id')
            ->where('ventas.estado', 'Activo')
            ->whereIn('ventas.local_id', $locales)
            ->whereBetween('ventas.venta_fecha', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()])
            // Restaurant persiste variantes del mismo producto con item_id
            // distintos. El ranking comercial se consolida por descripción.
            ->selectRaw('MIN(venta_detalles.item_id) as item_id, venta_detalles.descripcion, COALESCE(SUM(venta_detalles.importe), 0) as importe')
            ->groupBy('venta_detalles.descripcion')
            ->orderByDesc('importe')
            ->limit(15)
            ->get();
        $total = (float) $rows->sum('importe');

        return $rows->map(fn (VentaDetalle $row): array => [
            'codigo' => (string) $row->item_id,
            'descripcion' => (string) $row->descripcion,
            'importe' => (float) $row->importe,
            'participacion' => $total > 0 ? ((float) $row->importe / $total) * 100 : null,
        ]);
    }

    /** @param array{restaurant: Collection<int, CuotaVentaRestaurant>, unidades: Collection<int, array{tipo: string, id: string, codigo: string, nombre: string, local_id: ?string}>} $scope @return array{labels: array<int, string>, values: array<int, float>} */
    private function tendencia(array $scope, Carbon $desde, Carbon $hasta): array
    {
        $restaurantIds = $scope['restaurant']->pluck('local_id')->filter()->values()->all();
        $restaurant = Venta::query()
            ->where('estado', 'Activo')
            ->whereIn('local_id', $restaurantIds)
            ->whereBetween('venta_fecha', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()])
            ->selectRaw('DATE(venta_fecha) as fecha, COALESCE(SUM(subtotal), 0) as importe')
            ->groupByRaw('DATE(venta_fecha)')
            ->pluck('importe', 'fecha');
        $fechas = collect(CarbonPeriod::create($desde, $hasta))->map(fn (Carbon $fecha): string => $fecha->toDateString());

        return [
            'labels' => $fechas->map(fn (string $fecha): string => Carbon::parse($fecha)->format('d/m'))->all(),
            'values' => $fechas->map(fn (string $fecha): float => (float) $restaurant->get($fecha, 0))->all(),
        ];
    }

    /** @return Collection<int, CuotaVentaRestaurant> */
    private function restaurantPermitidas(?User $usuario, Carbon $periodo): Collection
    {
        return CuotaVentaRestaurant::query()
            ->whereDate('periodo', $periodo->copy()->startOfMonth()->toDateString())
            ->when($usuario?->isRestrictedToLocals(), fn (Builder $query): Builder => $query->whereIn('local_id', $usuario->assignedLocalIds()))
            ->get();
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function ordenarRango(Carbon $desde, Carbon $hasta): array
    {
        return $hasta->lessThan($desde) ? [$hasta, $desde] : [$desde, $hasta];
    }
}
