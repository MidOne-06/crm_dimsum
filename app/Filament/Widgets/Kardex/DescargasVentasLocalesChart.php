<?php

namespace App\Filament\Widgets\Kardex;

use Filament\Support\RawJs;

class DescargasVentasLocalesChart extends DescargasVentasChart
{
    protected ?string $heading = 'Ventas por local y producto';

    protected ?string $description = 'Eje X: locales · series: productos.';

    protected string $color = 'primary';

    protected ?string $maxHeight = '360px';

    protected function getData(): array
    {
        $query = $this->baseQuery();
        $locales = (clone $query)
            ->selectRaw('local_id, MAX(local_nombre) AS local_nombre, COALESCE(SUM(salida), 0) AS descargas')
            ->groupBy('local_id')
            ->orderByDesc('descargas')
            ->when(blank($this->analysisFilters['selectedLocals'] ?? []), fn ($query) => $query->limit(5))
            ->get();

        $productos = (clone $query)
            ->selectRaw('item_id, MAX(item_nombre) AS item_nombre, COALESCE(SUM(salida), 0) AS descargas')
            ->groupBy('item_id')
            ->orderByDesc('descargas')
            ->when(blank($this->analysisFilters['selectedProducts'] ?? []), fn ($query) => $query->limit(5))
            ->get();

        if ($locales->isEmpty() || $productos->isEmpty()) {
            return [];
        }

        $ventas = (clone $query)
            ->whereIn('local_id', $locales->pluck('local_id')->all())
            ->whereIn('item_id', $productos->pluck('item_id')->all())
            ->selectRaw('local_id, item_id, COALESCE(SUM(salida), 0) AS descargas')
            ->groupBy('local_id', 'item_id')
            ->get()
            ->mapWithKeys(fn ($row): array => ["{$row->local_id}:{$row->item_id}" => (float) $row->descargas]);

        return [
            'datasets' => $productos->values()->map(function ($producto, int $index) use ($locales, $ventas): array {
                $color = sprintf('hsl(%d, 68%%, 43%%)', ($index * 137) % 360);

                return [
                    'label' => $producto->item_nombre ?: 'Sin nombre',
                    'data' => $locales->map(fn ($local): float => $ventas["{$local->local_id}:{$producto->item_id}"] ?? 0)->all(),
                    'backgroundColor' => $color,
                    'borderColor' => $color,
                ];
            })->all(),
            'labels' => $locales->pluck('local_nombre')->map(fn ($name): string => $name ?: 'Sin nombre')->all(),
        ];
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
