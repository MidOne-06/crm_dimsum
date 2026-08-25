@php($historial = $this->historial())

<x-filament::section>
    <x-slot name="heading">Historial de extracciones</x-slot>

    @if ($historial->isEmpty())
        <x-filament::empty-state icon="heroicon-o-circle-stack" heading="Todavía no se ha corrido ninguna extracción." />
    @else
        <div class="opm-stock-table">
            <div class="fi-ta-content overflow-x-auto">
                <table class="fi-ta-table w-full text-start">
                    <thead>
                        <tr>
                            <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Cód.</span></th>
                            <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Rango</span></th>
                            <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Estado</span></th>
                            <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Procesadas</span></th>
                            <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Guardadas</span></th>
                            <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Fallidas</span></th>
                            <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Duración</span></th>
                            <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Iniciado</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($historial as $item)
                            <tr class="fi-ta-row">
                                <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $item->id }}</div></td>
                                <td class="fi-ta-cell"><div class="px-3 py-3 text-sm whitespace-nowrap text-gray-950 dark:text-white">{{ $item->filtros['fechaInicio'] ?? '—' }} al {{ $item->filtros['fechaFin'] ?? '—' }}</div></td>
                                <td class="fi-ta-cell"><div class="px-3 py-3"><span class="opm-status">{{ ucfirst(str_replace('_', ' ', $item->estado)) }}</span></div></td>
                                <td class="fi-ta-cell"><div class="opm-table-number px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $item->ventas_procesadas }}</div></td>
                                <td class="fi-ta-cell"><div class="opm-table-number px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $item->ventas_guardadas }}</div></td>
                                <td class="fi-ta-cell"><div class="opm-table-number px-3 py-3 text-sm text-danger-600 dark:text-danger-400">{{ $item->ventas_fallidas }}</div></td>
                                <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $item->duracion ?? '—' }}</div></td>
                                <td class="fi-ta-cell"><div class="px-3 py-3 text-sm whitespace-nowrap text-gray-950 dark:text-white">{{ $item->iniciado_at?->format('d/m/Y H:i') ?? '—' }}</div></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-filament::section>
