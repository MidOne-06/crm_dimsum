<?php

namespace App\Filament\Widgets\Ventas;

use App\Services\IndicadoresComercialesService;
use Carbon\Carbon;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

class IndicadoresComercialesTendenciaChart extends ChartWidget
{
    protected ?string $heading = 'Ventas diarias sin IGV';

    protected ?string $maxHeight = '280px';

    /** @var array<string, mixed> */
    public array $filters = [];

    protected function getData(): array
    {
        $tablero = app(IndicadoresComercialesService::class)->tablero(
            Carbon::parse((string) ($this->filters['desde'] ?? now()->startOfMonth()->toDateString()))->startOfDay(),
            Carbon::parse((string) ($this->filters['hasta'] ?? now()->toDateString()))->startOfDay(),
            array_values(array_filter((array) ($this->filters['unidades'] ?? []), 'is_string')),
            auth()->user(),
        );

        return [
            'datasets' => [[
                'label' => 'Ventas sin IGV',
                'data' => $tablero['tendencia']['values'],
                'borderColor' => '#0f766e',
                'backgroundColor' => 'rgba(13, 148, 136, 0.12)',
                'fill' => true,
                'tension' => 0.25,
            ]],
            'labels' => $tablero['tendencia']['labels'],
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
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (context) => `S/ ${new Intl.NumberFormat('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(context.parsed.y)}`,
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
