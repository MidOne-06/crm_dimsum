<?php

namespace App\Filament\Widgets\Kardex;

use Carbon\Carbon;
use Filament\Support\RawJs;

class DescargasVentasTendenciaChart extends DescargasVentasChart
{
    protected ?string $heading = 'Ventas diarias por producto';

    protected ?string $description = 'Eje X: días · series: productos.';

    protected string $color = 'warning';

    protected function getData(): array
    {
        $query = $this->baseQuery();
        $productos = (clone $query)
            ->selectRaw('item_id, MAX(item_nombre) AS item_nombre, COALESCE(SUM(salida), 0) AS ventas')
            ->groupBy('item_id')
            ->orderByDesc('ventas')
            ->when(blank($this->analysisFilters['selectedProducts'] ?? []), fn ($query) => $query->limit(5))
            ->get();

        if ($productos->isEmpty()) {
            return [];
        }

        $rows = (clone $query)
            ->whereIn('item_id', $productos->pluck('item_id')->all())
            ->selectRaw('fecha, item_id, COALESCE(SUM(salida), 0) AS descargas')
            ->groupBy('fecha', 'item_id')
            ->orderBy('fecha')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $fechas = $rows->pluck('fecha')->unique()->values();
        $ventas = $rows->mapWithKeys(fn ($row): array => ["{$row->item_id}:{$row->fecha}" => (float) $row->descargas]);

        return [
            'datasets' => $productos->values()->map(function ($producto, int $index) use ($fechas, $ventas): array {
                $color = sprintf('hsl(%d, 68%%, 43%%)', ($index * 137) % 360);

                return [
                    'label' => $producto->item_nombre ?: 'Sin nombre',
                    'data' => $fechas->map(fn ($fecha): float => $ventas["{$producto->item_id}:{$fecha}"] ?? 0)->all(),
                    'borderColor' => $color,
                    'backgroundColor' => $color,
                    'fill' => false,
                    'tension' => 0.25,
                ];
            })->all(),
            'labels' => $fechas->map(fn ($date): string => Carbon::parse($date)->format('d/m'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
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
