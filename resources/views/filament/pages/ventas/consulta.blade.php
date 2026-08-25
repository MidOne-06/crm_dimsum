<x-filament-panels::page>
    <div class="space-y-6" x-data x-on:sales-results-ready.window="$nextTick(() => document.getElementById('sales-results')?.scrollIntoView({ behavior: 'smooth', block: 'start' }))">
        @include('filament.pages.ventas.partials.filters')

        @if ($hasSearched)
            <x-filament::section id="sales-results">
                <x-slot name="heading">Resultados</x-slot>

                @if ($resultError)
                    <p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ $resultError }}</p>
                @elseif (count($salesRows))
                    @include('filament.pages.ventas.partials.table', ['withDetail' => true])
                @else
                    <x-filament::empty-state icon="heroicon-o-inbox" heading="No hay ventas para los filtros seleccionados." />
                @endif
            </x-filament::section>
        @endif
    </div>

    <x-filament::modal id="sale-detail-modal" width="6xl" sticky-header sticky-footer>
        <x-slot name="heading">Detalle de venta {{ $detail['id'] ?? '' ? '#'.$detail['id'] : '' }}</x-slot>

        @if ($detailLoading)
            <x-filament::loading-indicator class="h-5 w-5" />
        @elseif ($detailError)
            <p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ $detailError }}</p>
        @elseif ($detail)
            <div class="opm-detail-summary">
                <div><span>Cliente</span><strong>{{ $detail['cliente']['nombre'] ?: '—' }}</strong></div>
                <div><span>Local</span><strong>{{ $detail['local'] ?: '—' }}</strong></div>
                <div><span>Comprobante</span><strong>{{ trim(($detail['comprobante']['tipo'] ?? '').' '.($detail['comprobante']['serie'] ?? '').'-'.($detail['comprobante']['numero'] ?? ''), ' -') ?: '—' }}</strong></div>
                <div><span>Fecha</span><strong>{{ $detail['fecha'] ?: '—' }}</strong></div>
                <div><span>Total</span><strong>{{ number_format((float) ($detail['total'] ?? 0), 2) }} {{ $detail['moneda'] ?? '' }}</strong></div>
            </div>

            <div class="opm-stock-table">
                <div class="fi-ta-content overflow-x-auto">
                    <table class="fi-ta-table w-full text-start">
                        <thead><tr>
                            <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Ítem</span></th>
                            <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Cantidad</span></th>
                            <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Precio</span></th>
                            <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Descuento</span></th>
                            <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Importe</span></th>
                        </tr></thead>
                        <tbody>
                            @forelse ($detail['items'] ?? [] as $item)
                                <tr class="fi-ta-row">
                                    <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $item['descripcion'] ?? '—' }}</div></td>
                                    <td class="fi-ta-cell"><div class="opm-table-number px-3 py-3 text-sm text-gray-950 dark:text-white">{{ number_format((float) ($item['cantidad'] ?? 0), 3) }}</div></td>
                                    <td class="fi-ta-cell"><div class="opm-table-number px-3 py-3 text-sm text-gray-950 dark:text-white">{{ number_format((float) ($item['precio'] ?? 0), 2) }}</div></td>
                                    <td class="fi-ta-cell"><div class="opm-table-number px-3 py-3 text-sm text-gray-950 dark:text-white">{{ number_format((float) ($item['descuento'] ?? 0), 2) }}</div></td>
                                    <td class="fi-ta-cell"><div class="opm-table-number px-3 py-3 text-sm font-semibold text-gray-950 dark:text-white">{{ number_format((float) ($item['importe'] ?? 0), 2) }}</div></td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-3 py-6 text-center text-sm text-gray-500">Sin ítems.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <x-slot name="footerActions"><x-filament::button color="gray" wire:click="closeDetail">Cerrar</x-filament::button></x-slot>
    </x-filament::modal>
</x-filament-panels::page>
