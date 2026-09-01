<?php

namespace App\Filament\Widgets\Kardex;

use Carbon\Carbon;
use Filament\Support\RawJs;

class DescargasVentasProductosLocalesChart extends DescargasVentasChart
{
    protected string $color = 'success';

    protected ?string $maxHeight = '400px';

    protected function getData(): array
    {
        $query = $this->baseQuery();

        $locales = (clone $query)
            ->selectRaw('local_id, MAX(local_nombre) AS local_nombre, COALESCE(SUM(salida), 0) AS ventas')
            ->groupBy('local_id')
            ->orderByDesc('ventas')
            ->when(blank($this->analysisFilters['selectedLocals'] ?? []), fn ($query) => $query->limit(5))
            ->get();

        if ($locales->isEmpty()) {
            return [];
        }

        $localIds = $locales->pluck('local_id')->all();

        if ($this->comparisonDimension() === 'dia') {
            return $this->dailyData($query, $locales, $localIds);
        }

        $productos = (clone $query)
            ->selectRaw('item_id, MAX(item_nombre) AS item_nombre, COALESCE(SUM(salida), 0) AS ventas')
            ->groupBy('item_id')
            ->orderByDesc('ventas')
            ->when(blank($this->analysisFilters['selectedProducts'] ?? []), fn ($query) => $query->limit(5))
            ->get();

        if ($productos->isEmpty()) {
            return [];
        }

        $itemIds = $productos->pluck('item_id')->all();
        $colors = $this->chartColors($productos->count());
        $ventas = (clone $query)
            ->whereIn('local_id', $localIds)
            ->whereIn('item_id', $itemIds)
            ->selectRaw('local_id, item_id, COALESCE(SUM(salida), 0) AS ventas')
            ->groupBy('local_id', 'item_id')
            ->get()
            ->mapWithKeys(fn ($row): array => ["{$row->local_id}:{$row->item_id}" => (float) $row->ventas]);

        return [
            'datasets' => $productos->values()->map(function ($producto, int $index) use ($locales, $ventas, $colors): array {
                return [
                    'label' => $producto->item_nombre ?: 'Sin nombre',
                    'data' => $locales->map(fn ($local): float => $ventas["{$local->local_id}:{$producto->item_id}"] ?? 0)->all(),
                    'backgroundColor' => $colors[$index],
                    'borderColor' => $colors[$index],
                ];
            })->all(),
            'labels' => $locales->map(function ($local): string {
                $name = $local->local_nombre ?: 'Sin nombre';

                return mb_strlen($name) > 26 ? mb_substr($name, 0, 26).'…' : $name;
            })->all(),
        ];
    }

    public function getHeading(): string
    {
        return $this->comparisonDimension() === 'dia'
            ? 'Ventas diarias por producto'
            : 'Productos vendidos por local';
    }

    public function getDescription(): ?string
    {
        return $this->comparisonDimension() === 'dia'
            ? 'Eje X: días · series: productos seleccionados.'
            : 'Selecciona hasta 5 locales y 10 productos. Sin selección, muestra los 5 principales.';
    }

    /** @param \Illuminate\Support\Collection<int, object> $locales
     *  @param array<int, string|int> $localIds
     *  @return array<string, mixed>
     */
    protected function dailyData($query, $locales, array $localIds): array
    {
        $productos = (clone $query)
            ->selectRaw('item_id, MAX(item_nombre) AS item_nombre, COALESCE(SUM(salida), 0) AS ventas')
            ->groupBy('item_id')
            ->orderByDesc('ventas')
            ->when(blank($this->analysisFilters['selectedProducts'] ?? []), fn ($query) => $query->limit(5))
            ->get();

        if ($productos->isEmpty()) {
            return [];
        }

        $itemIds = $productos->pluck('item_id')->all();
        $rows = (clone $query)
            ->whereIn('local_id', $localIds)
            ->whereIn('item_id', $itemIds)
            ->selectRaw('fecha, item_id, COALESCE(SUM(salida), 0) AS ventas')
            ->groupBy('fecha', 'item_id')
            ->orderBy('fecha')
            ->get();

        $fechas = $rows->pluck('fecha')->unique()->values();
        $ventas = $rows->mapWithKeys(fn ($row): array => ["{$row->item_id}:{$row->fecha}" => (float) $row->ventas]);
        $colors = $this->chartColors($productos->count());

        return [
            'datasets' => $productos->values()->map(function ($producto, int $index) use ($fechas, $ventas, $colors): array {
                return [
                    'label' => $producto->item_nombre ?: 'Sin nombre',
                    'data' => $fechas->map(fn ($fecha): float => $ventas["{$producto->item_id}:{$fecha}"] ?? 0)->all(),
                    'backgroundColor' => $colors[$index],
                    'borderColor' => $colors[$index],
                ];
            })->all(),
            'labels' => $fechas->map(fn ($fecha): string => Carbon::parse($fecha)->format('d/m'))->all(),
        ];
    }

    /** @return array<int, string> */
    protected function chartColors(int $count): array
    {
        return collect(range(0, max(0, $count - 1)))
            ->map(fn (int $index): string => sprintf('hsl(%d, 68%%, 43%%)', ($index * 137) % 360))
            ->all();
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                maintainAspectRatio: false,
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: (context) => `${context.dataset.label}: ${new Intl.NumberFormat('es-PE', { maximumFractionDigits: 0 }).format(context.parsed.y)}`,
                        },
                    },
                },
                scales: {
                    x: { ticks: { maxRotation: 45, minRotation: 45 } },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: (value) => new Intl.NumberFormat('es-PE', { maximumFractionDigits: 0 }).format(value),
                        },
                    },
                },
            }
        JS);
    }
}
