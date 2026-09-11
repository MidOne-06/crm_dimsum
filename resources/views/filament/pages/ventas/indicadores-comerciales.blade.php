<x-filament-panels::page>
    @php
        $tablero = $this->tablero();
        $periodo = $tablero['periodo'];
        $ytd = $tablero['ytd'];
        $dinero = static fn (?float $valor): string => $valor === null ? '—' : 'S/ '.number_format($valor, 2);
        $porcentaje = static fn (?float $valor): string => $valor === null ? '—' : number_format($valor, 2).'%';
        $estadoCosto = static fn (array $metricas): string => ($metricas['cobertura_costos'] ?? 0) >= 99.999
            ? 'Costos verificados'
            : 'Cobertura de costos: '.number_format((float) ($metricas['cobertura_costos'] ?? 0), 1).'%';
    @endphp

    <div class="space-y-5">
        <x-filament::section compact>
            <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                <span class="font-medium text-gray-950 dark:text-white">{{ \Carbon\Carbon::parse($desde)->format('d/m/Y') }} al {{ \Carbon\Carbon::parse($hasta)->format('d/m/Y') }}</span>
                <span class="text-gray-500 dark:text-gray-400">{{ $unidades === [] ? 'Todas las tiendas y canales' : count($unidades).' seleccionados' }} · {{ $tablero['unidades'] }} unidades</span>
            </div>
        </x-filament::section>

        <x-filament::section compact>
            <x-slot name="heading">Período seleccionado</x-slot>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Ventas sin IGV</p>
                    <p class="text-xl font-semibold text-primary-600 dark:text-primary-400">{{ $dinero($periodo['sin_igv']) }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Cuota sin IGV</p>
                    <p class="text-xl font-semibold text-gray-950 dark:text-white">{{ $dinero($periodo['cuota_sin_igv']) }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $periodo['cuotas_cargadas'] }}/{{ $periodo['cuotas_requeridas'] }} cuotas</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Avance</p>
                    <p class="text-xl font-semibold {{ ($periodo['avance'] ?? 0) >= 100 ? 'text-success-600 dark:text-success-400' : 'text-warning-600 dark:text-warning-400' }}">{{ $porcentaje($periodo['avance']) }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">TKP</p>
                    <p class="text-xl font-semibold text-gray-950 dark:text-white">{{ $dinero($periodo['tkp']) }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ number_format($periodo['tickets']) }} tickets</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">MB</p>
                    <p class="text-xl font-semibold text-gray-950 dark:text-white">{{ $porcentaje($periodo['mb']) }}</p>
                    @if ($periodo['mb'] === null)
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $estadoCosto($periodo) }}</p>
                    @endif
                </div>
            </div>
        </x-filament::section>

        <x-filament::section compact>
            <x-slot name="heading">YTD {{ \Carbon\Carbon::parse($hasta)->year }}</x-slot>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Ventas sin IGV</p>
                    <p class="text-xl font-semibold text-primary-600 dark:text-primary-400">{{ $dinero($ytd['sin_igv']) }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Cuota sin IGV</p>
                    <p class="text-xl font-semibold text-gray-950 dark:text-white">{{ $dinero($ytd['cuota_sin_igv']) }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $ytd['cuotas_cargadas'] }}/{{ $ytd['cuotas_requeridas'] }} cuotas</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Avance</p>
                    <p class="text-xl font-semibold {{ ($ytd['avance'] ?? 0) >= 100 ? 'text-success-600 dark:text-success-400' : 'text-warning-600 dark:text-warning-400' }}">{{ $porcentaje($ytd['avance']) }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">TKP</p>
                    <p class="text-xl font-semibold text-gray-950 dark:text-white">{{ $dinero($ytd['tkp']) }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ number_format($ytd['tickets']) }} tickets</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">MB</p>
                    <p class="text-xl font-semibold text-gray-950 dark:text-white">{{ $porcentaje($ytd['mb']) }}</p>
                    @if ($ytd['mb'] === null)
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $estadoCosto($ytd) }}</p>
                    @endif
                </div>
            </div>
        </x-filament::section>

        @livewire(\App\Filament\Widgets\Ventas\IndicadoresComercialesTendenciaChart::class, ['filters' => $this->filtrosGrafico()], key('indicadores-comerciales-tendencia-'.md5(json_encode($this->filtrosGrafico()))))

        <div class="grid gap-5 xl:grid-cols-2">
            @livewire(\App\Livewire\Ventas\RankingVentasTable::class, ['rows' => $this->rankingVentas()], key('ranking-ventas-'.md5(json_encode([$desde, $hasta, $unidades]))))
            @livewire(\App\Livewire\Ventas\RankingProductosTable::class, ['rows' => $this->rankingProductos()], key('ranking-productos-'.md5(json_encode([$desde, $hasta, $unidades]))))
        </div>
    </div>
</x-filament-panels::page>
