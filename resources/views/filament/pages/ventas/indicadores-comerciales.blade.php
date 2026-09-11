<x-filament-panels::page>
    @php
        $tablero = $this->tablero();
        $periodo = $tablero['periodo'];
        $ytd = $tablero['ytd'];
        $dinero = static fn (?float $valor): string => $valor === null ? '—' : 'S/ '.number_format($valor, 2);
        $porcentaje = static fn (?float $valor): string => $valor === null ? '—' : number_format($valor, 2).'%';
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
                        <p class="text-xs text-gray-500 dark:text-gray-400">Costo Restaurant no cargado</p>
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
                        <p class="text-xs text-gray-500 dark:text-gray-400">Costo Restaurant no cargado</p>
                    @endif
                </div>
            </div>
        </x-filament::section>

        @livewire(\App\Filament\Widgets\Ventas\IndicadoresComercialesTendenciaChart::class, ['filters' => $this->filtrosGrafico()], key('indicadores-comerciales-tendencia-'.md5(json_encode($this->filtrosGrafico()))))

        <div class="grid gap-5 xl:grid-cols-2">
            <x-filament::section compact>
                <x-slot name="heading">Ranking ventas</x-slot>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <tr>
                                <th class="px-2 py-2">#</th>
                                <th class="px-2 py-2">Código</th>
                                <th class="px-2 py-2">Tienda</th>
                                <th class="px-2 py-2 text-right">Venta</th>
                                <th class="px-2 py-2 text-right">Cuota</th>
                                <th class="px-2 py-2 text-right">Avance</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                            @forelse ($tablero['ranking_locales'] as $indice => $fila)
                                <tr>
                                    <td class="px-2 py-2 text-gray-500">{{ $indice + 1 }}</td>
                                    <td class="px-2 py-2 font-medium">{{ $fila['codigo'] }}</td>
                                    <td class="px-2 py-2">{{ $fila['nombre'] }}</td>
                                    <td class="px-2 py-2 text-right">{{ $dinero($fila['sin_igv']) }}</td>
                                    <td class="px-2 py-2 text-right">{{ $dinero($fila['cuota_sin_igv']) }}</td>
                                    <td class="px-2 py-2 text-right">{{ $porcentaje($fila['avance']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-2 py-6 text-center text-gray-500">No hay unidades para el filtro.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>

            <x-filament::section compact>
                <x-slot name="heading">Ranking productos Restaurant</x-slot>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <tr>
                                <th class="px-2 py-2">SKU</th>
                                <th class="px-2 py-2">Producto</th>
                                <th class="px-2 py-2 text-right">Venta</th>
                                <th class="px-2 py-2 text-right">Participación</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                            @forelse ($tablero['ranking_productos'] as $fila)
                                <tr>
                                    <td class="px-2 py-2 font-medium">{{ $fila['codigo'] }}</td>
                                    <td class="px-2 py-2">{{ $fila['descripcion'] }}</td>
                                    <td class="px-2 py-2 text-right">{{ $dinero($fila['importe']) }}</td>
                                    <td class="px-2 py-2 text-right">{{ $porcentaje($fila['participacion']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-2 py-6 text-center text-gray-500">No hay productos Restaurant para el filtro.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
