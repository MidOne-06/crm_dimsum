<?php

namespace App\Services;

use App\Models\CanalVentaExterna;
use App\Models\CuotaVentaExterna;
use App\Models\CuotaVentaRestaurant;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaDetalle;
use App\Models\VentaExternaDiaria;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class IndicadoresComercialesService
{
    /** @return array<string, string> */
    public function opcionesUnidades(?User $usuario = null): array
    {
        $restaurant = $this->restaurantPermitidas($usuario)
            ->sortBy('local')
            ->mapWithKeys(fn (CuotaVentaRestaurant $cuota): array => ["restaurant:{$cuota->codigo}" => "{$cuota->codigo} · {$cuota->local}"]);

        $externas = $this->externasPermitidas($usuario)
            ->sortBy('nombre')
            ->mapWithKeys(fn (CanalVentaExterna $canal): array => ["externa:{$canal->id}" => "{$canal->codigo} · {$canal->nombre}"]);

        return $restaurant->union($externas)->all();
    }

    /** @param array<int, string> $seleccion @return array<string, mixed> */
    public function tablero(Carbon $desde, Carbon $hasta, array $seleccion = [], ?User $usuario = null): array
    {
        [$desde, $hasta] = $this->ordenarRango($desde, $hasta);
        $scope = $this->scope($seleccion, $usuario);

        return [
            'periodo' => $this->metricas($scope, $desde, $hasta),
            'ytd' => $this->metricas($scope, $hasta->copy()->startOfYear(), $hasta),
            'ranking_locales' => $this->rankingLocales($scope, $desde, $hasta),
            'ranking_productos' => $this->rankingProductos($scope, $desde, $hasta),
            'tendencia' => $this->tendencia($scope, $desde, $hasta),
            'unidades' => $scope['unidades']->count(),
        ];
    }

    /** @param array<int, string> $seleccion @return array{restaurant: Collection<int, CuotaVentaRestaurant>, externas: Collection<int, CanalVentaExterna>, unidades: Collection<int, array{tipo: string, id: string, codigo: string, nombre: string, local_id: ?string}>} */
    private function scope(array $seleccion, ?User $usuario): array
    {
        $restaurant = $this->restaurantPermitidas($usuario);
        $externas = $this->externasPermitidas($usuario);
        $seleccion = array_values(array_filter($seleccion, fn (mixed $valor): bool => is_string($valor) && $valor !== ''));

        $restaurantCodigos = collect($seleccion)
            ->filter(fn (string $valor): bool => str_starts_with($valor, 'restaurant:'))
            ->map(fn (string $valor): string => str_replace('restaurant:', '', $valor))
            ->values();
        $externasIds = collect($seleccion)
            ->filter(fn (string $valor): bool => str_starts_with($valor, 'externa:'))
            ->map(fn (string $valor): int => (int) str_replace('externa:', '', $valor))
            ->filter()
            ->values();

        if ($seleccion !== []) {
            $restaurant = $restaurant->when($restaurantCodigos->isNotEmpty(), fn (Collection $rows): Collection => $rows->whereIn('codigo', $restaurantCodigos->all()), fn (): Collection => collect());
            $externas = $externas->when($externasIds->isNotEmpty(), fn (Collection $rows): Collection => $rows->whereIn('id', $externasIds->all()), fn (): Collection => collect());
        }

        $unidades = $restaurant->map(fn (CuotaVentaRestaurant $cuota): array => [
            'tipo' => 'restaurant',
            'id' => $cuota->codigo,
            'codigo' => $cuota->codigo,
            'nombre' => $cuota->local,
            'local_id' => $cuota->local_id,
        ])->values()->merge($externas->map(fn (CanalVentaExterna $canal): array => [
            'tipo' => 'externa',
            'id' => (string) $canal->id,
            'codigo' => $canal->codigo,
            'nombre' => $canal->nombre,
            'local_id' => null,
        ])->values());

        return compact('restaurant', 'externas', 'unidades');
    }

    /** @param array{restaurant: Collection<int, CuotaVentaRestaurant>, externas: Collection<int, CanalVentaExterna>, unidades: Collection<int, array{tipo: string, id: string, codigo: string, nombre: string, local_id: ?string}>} $scope @return array<string, mixed> */
    private function metricas(array $scope, Carbon $desde, Carbon $hasta): array
    {
        $ventas = $this->ventasPorUnidad($scope, $desde, $hasta);
        $cuotas = $this->cuotasPorUnidad($scope, $desde, $hasta);
        $sinIgv = (float) $ventas->sum('sin_igv');
        $conIgv = (float) $ventas->sum('con_igv');
        $tickets = (int) $ventas->sum('tickets');
        $cuota = $cuotas['completa'] ? (float) collect($cuotas['por_unidad'])->sum('sin_igv') : null;
        $tieneRestaurantConVenta = $ventas->contains(fn (array $venta): bool => $venta['tipo'] === 'restaurant' && $venta['sin_igv'] > 0);
        $costo = (float) $ventas->where('tipo', 'externa')->sum('costo');

        return [
            'sin_igv' => $sinIgv,
            'con_igv' => $conIgv,
            'tickets' => $tickets,
            'cuota_sin_igv' => $cuota,
            'avance' => $cuota !== null && $cuota > 0 ? ($sinIgv / $cuota) * 100 : null,
            'tkp' => $tickets > 0 ? $conIgv / $tickets : null,
            // Restaurant no guarda costo de venta confiable. No se inventa MB
            // mientras la selección incluya ventas Restaurant.
            'mb' => ! $tieneRestaurantConVenta && $sinIgv > 0 ? (($sinIgv - $costo) / $sinIgv) * 100 : null,
            'cuotas_cargadas' => $cuotas['cargadas'],
            'cuotas_requeridas' => $cuotas['requeridas'],
        ];
    }

    /** @param array{restaurant: Collection<int, CuotaVentaRestaurant>, externas: Collection<int, CanalVentaExterna>, unidades: Collection<int, array{tipo: string, id: string, codigo: string, nombre: string, local_id: ?string}>} $scope @return Collection<int, array{tipo: string, id: string, sin_igv: float, con_igv: float, costo: float, tickets: int}> */
    private function ventasPorUnidad(array $scope, Carbon $desde, Carbon $hasta): Collection
    {
        $restaurantIds = $scope['restaurant']->pluck('local_id')->filter()->values()->all();
        $externasIds = $scope['externas']->pluck('id')->all();
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

        $externas = VentaExternaDiaria::query()
            ->where('estado', 'activa')
            ->whereIn('canal_id', $externasIds)
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->selectRaw('canal_id, COALESCE(SUM(venta_sin_igv), 0) as sin_igv, COALESCE(SUM(venta_con_igv), 0) as con_igv, COALESCE(SUM(costo_sin_igv), 0) as costo, COALESCE(SUM(tickets), 0) as tickets')
            ->groupBy('canal_id')
            ->get()
            ->mapWithKeys(fn (VentaExternaDiaria $venta): array => [(string) $venta->canal_id => [
                'sin_igv' => (float) $venta->sin_igv,
                'con_igv' => (float) $venta->con_igv,
                'costo' => (float) $venta->costo,
                'tickets' => (int) $venta->tickets,
            ]]);

        return $scope['unidades']->map(function (array $unidad) use ($restaurant, $externas): array {
            $suma = $unidad['tipo'] === 'restaurant'
                ? ($restaurant->get((string) $unidad['local_id']) ?? [])
                : ($externas->get($unidad['id']) ?? []);

            return [
                'tipo' => $unidad['tipo'],
                'id' => $unidad['id'],
                'sin_igv' => (float) ($suma['sin_igv'] ?? 0),
                'con_igv' => (float) ($suma['con_igv'] ?? 0),
                'costo' => (float) ($suma['costo'] ?? 0),
                'tickets' => (int) ($suma['tickets'] ?? 0),
            ];
        });
    }

    /** @param array{restaurant: Collection<int, CuotaVentaRestaurant>, externas: Collection<int, CanalVentaExterna>, unidades: Collection<int, array{tipo: string, id: string, codigo: string, nombre: string, local_id: ?string}>} $scope @return array{por_unidad: array<string, array{sin_igv: float, con_igv: float, completa: bool}>, cargadas: int, requeridas: int, completa: bool} */
    private function cuotasPorUnidad(array $scope, Carbon $desde, Carbon $hasta): array
    {
        $periodos = collect(CarbonPeriod::create($desde->copy()->startOfMonth(), '1 month', $hasta->copy()->startOfMonth()))
            ->map(fn (Carbon $fecha): string => $fecha->toDateString())
            ->values();
        $restaurantCodigos = $scope['restaurant']->pluck('codigo')->all();
        $externasIds = $scope['externas']->pluck('id')->all();

        $restaurant = CuotaVentaRestaurant::query()
            ->whereIn('codigo', $restaurantCodigos)
            ->whereIn('periodo', $periodos->all())
            ->get()
            ->groupBy('codigo');
        $externas = CuotaVentaExterna::query()
            ->whereIn('canal_id', $externasIds)
            ->whereIn('periodo', $periodos->all())
            ->get()
            ->groupBy(fn (CuotaVentaExterna $cuota): string => (string) $cuota->canal_id);

        $porUnidad = [];
        foreach ($scope['unidades'] as $unidad) {
            $filas = $unidad['tipo'] === 'restaurant'
                ? ($restaurant->get($unidad['id']) ?? collect())
                : ($externas->get($unidad['id']) ?? collect());
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

    /** @param array{restaurant: Collection<int, CuotaVentaRestaurant>, externas: Collection<int, CanalVentaExterna>, unidades: Collection<int, array{tipo: string, id: string, codigo: string, nombre: string, local_id: ?string}>} $scope @return Collection<int, array<string, mixed>> */
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

    /** @param array{restaurant: Collection<int, CuotaVentaRestaurant>, externas: Collection<int, CanalVentaExterna>, unidades: Collection<int, array{tipo: string, id: string, codigo: string, nombre: string, local_id: ?string}>} $scope @return Collection<int, array<string, mixed>> */
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

    /** @param array{restaurant: Collection<int, CuotaVentaRestaurant>, externas: Collection<int, CanalVentaExterna>, unidades: Collection<int, array{tipo: string, id: string, codigo: string, nombre: string, local_id: ?string}>} $scope @return array{labels: array<int, string>, values: array<int, float>} */
    private function tendencia(array $scope, Carbon $desde, Carbon $hasta): array
    {
        $restaurantIds = $scope['restaurant']->pluck('local_id')->filter()->values()->all();
        $externasIds = $scope['externas']->pluck('id')->all();
        $restaurant = Venta::query()
            ->where('estado', 'Activo')
            ->whereIn('local_id', $restaurantIds)
            ->whereBetween('venta_fecha', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()])
            ->selectRaw('DATE(venta_fecha) as fecha, COALESCE(SUM(subtotal), 0) as importe')
            ->groupByRaw('DATE(venta_fecha)')
            ->pluck('importe', 'fecha');
        $externas = VentaExternaDiaria::query()
            ->where('estado', 'activa')
            ->whereIn('canal_id', $externasIds)
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->selectRaw('fecha, COALESCE(SUM(venta_sin_igv), 0) as importe')
            ->groupBy('fecha')
            ->pluck('importe', 'fecha');
        $fechas = collect(CarbonPeriod::create($desde, $hasta))->map(fn (Carbon $fecha): string => $fecha->toDateString());

        return [
            'labels' => $fechas->map(fn (string $fecha): string => Carbon::parse($fecha)->format('d/m'))->all(),
            'values' => $fechas->map(fn (string $fecha): float => (float) ($restaurant->get($fecha, 0) + $externas->get($fecha, 0)))->all(),
        ];
    }

    /** @return Collection<int, CuotaVentaRestaurant> */
    private function restaurantPermitidas(?User $usuario): Collection
    {
        return CuotaVentaRestaurant::query()
            ->where('periodo', '2026-09-01')
            ->when($usuario?->isRestrictedToLocals(), fn (Builder $query): Builder => $query->whereIn('local_id', $usuario->assignedLocalIds()))
            ->get();
    }

    /** @return Collection<int, CanalVentaExterna> */
    private function externasPermitidas(?User $usuario): Collection
    {
        if ($usuario?->isRestrictedToLocals()) {
            return collect();
        }

        return CanalVentaExterna::query()->where('activo', true)->get();
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function ordenarRango(Carbon $desde, Carbon $hasta): array
    {
        return $hasta->lessThan($desde) ? [$hasta, $desde] : [$desde, $hasta];
    }
}
