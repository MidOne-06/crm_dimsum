<x-filament-panels::page>
    <div
        class="space-y-6"
        x-data
        x-on:stock-results-ready.window="$nextTick(() => document.getElementById('stock-results')?.scrollIntoView({ behavior: 'smooth', block: 'start' }))"
    >

        @include('filament.pages.stock.partials.filters-form')

        @if ($hasSearched)
            <x-filament::section id="stock-results">
                <x-slot name="heading">Resumen por local e ítem</x-slot>

                @include('filament.pages.stock.partials.report-filters', ['showAlmacenFilter' => true])

                @php($page = $this->summaryPage())
                @if (count($page['rows']))
                    <div class="mb-3 flex justify-end">
                        <x-filament::button
                            size="sm"
                            color="gray"
                            icon="heroicon-o-arrow-down-tray"
                            wire:click="exportarExcel"
                            wire:loading.attr="disabled"
                            wire:target="exportarExcel"
                        >
                            <span wire:loading.remove wire:target="exportarExcel">Exportar Excel</span>
                            <span wire:loading wire:target="exportarExcel">Generando Excel…</span>
                        </x-filament::button>
                    </div>
                    <div class="opm-stock-table">
                    <div class="fi-ta-content overflow-x-auto">
                        <table class="fi-ta-table w-full text-start">
                            <thead>
                                <tr>
                                    <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Local</span></th>
                                    <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Ítem</span></th>
                                    <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Almacenes</span></th>
                                    <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Stock consolidado</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($page['rows'] as $row)
                                    <tr class="fi-ta-row">
                                        <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $row['local'] ?? '—' }}</div></td>
                                        <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $row['item'] ?? '—' }}</div></td>
                                        <td class="fi-ta-cell"><div class="opm-table-number px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $row['almacenes'] ?? 0 }}</div></td>
                                        <td class="fi-ta-cell"><div class="opm-table-number px-3 py-3 text-sm font-medium text-gray-950 dark:text-white">{{ number_format((float) ($row['stockActual'] ?? 0), 3) }} {{ $row['unidad'] ?? '' }}</div></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @include('filament.pages.stock.partials.report-pagination', ['page' => $page])
                    </div>
                @else
                    <x-filament::empty-state icon="heroicon-o-archive-box" heading="No hay stock para los filtros consolidados seleccionados." />
                @endif
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
