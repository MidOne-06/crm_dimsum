<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section collapsible :collapsed="$hasSearched" class="opm-query-section">
            <x-slot name="heading">Filtros</x-slot>

            <form wire:submit.prevent="search" class="space-y-4">
                {{ $this->form }}

                <x-filament::fieldset label="Fecha" class="opm-filter-date">
                    @include('filament.pages.ventas.partials.date-range-picker')
                </x-filament::fieldset>

                <div class="opm-form-actions">
                    <x-filament::button type="submit" icon="heroicon-m-magnifying-glass" wire:loading.attr="disabled" wire:target="search">
                        Consultar
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>

        @if ($hasSearched)
            <x-filament::section id="sales-history-results">
                <x-slot name="heading">Resultados</x-slot>
                @include('filament.pages.ventas.partials.historico-tabla')
            </x-filament::section>
        @endif
    </div>

    <x-filament::modal id="sale-history-detail-modal" width="6xl" sticky-header sticky-footer>
        <x-slot name="heading">Detalle de venta {{ $this->detail()?->venta_id ? '#'.$this->detail()->venta_id : '' }}</x-slot>

        @php($detail = $this->detail())

        @if ($detail)
            <div class="opm-detail-summary">
                <div><span>Cliente</span><strong>{{ $detail->cliente ?: '—' }}</strong></div>
                <div><span>Local</span><strong>{{ $detail->local ?: '—' }}</strong></div>
                <div><span>Comprobante</span><strong>{{ trim(($detail->comprobante_tipo ?? '').' '.($detail->comprobante_serie ?? '').'-'.($detail->comprobante_numero ?? ''), ' -') ?: '—' }}</strong></div>
                <div><span>Fecha</span><strong>{{ $detail->venta_fecha?->format('d/m/Y H:i:s') ?: '—' }}</strong></div>
                <div><span>Total</span><strong>{{ number_format((float) $detail->total, 2) }} {{ $detail->moneda }}</strong></div>
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
                            @forelse ($detail->detalles as $item)
                                <tr class="fi-ta-row">
                                    <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $item->descripcion ?? '—' }}</div></td>
                                    <td class="fi-ta-cell"><div class="opm-table-number px-3 py-3 text-sm text-gray-950 dark:text-white">{{ number_format((float) $item->cantidad, 3) }}</div></td>
                                    <td class="fi-ta-cell"><div class="opm-table-number px-3 py-3 text-sm text-gray-950 dark:text-white">{{ number_format((float) $item->precio, 2) }}</div></td>
                                    <td class="fi-ta-cell"><div class="opm-table-number px-3 py-3 text-sm text-gray-950 dark:text-white">{{ number_format((float) $item->descuento, 2) }}</div></td>
                                    <td class="fi-ta-cell"><div class="opm-table-number px-3 py-3 text-sm font-semibold text-gray-950 dark:text-white">{{ number_format((float) $item->importe, 2) }}</div></td>
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
