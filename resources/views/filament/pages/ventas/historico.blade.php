<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section collapsible :collapsed="$hasSearched" class="crm-query-section">
            <x-slot name="heading">Filtros</x-slot>

            <form wire:submit.prevent="search" class="space-y-4">
                {{ $this->form }}

                <x-filament::fieldset label="Fecha" class="crm-filter-date">
                    @include('filament.pages.ventas.partials.date-range-picker')
                </x-filament::fieldset>

                <div class="crm-form-actions">
                    <x-filament::button type="submit" icon="heroicon-m-magnifying-glass" wire:loading.attr="disabled" wire:target="search">
                        Consultar
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>

        @if ($hasSearched)
            <x-filament::section id="sales-history-results">
                {{ $this->table }}
            </x-filament::section>
        @endif
    </div>

    <x-filament::modal id="sale-history-detail-modal" width="6xl" sticky-header sticky-footer>
        <x-slot name="heading">Detalle de venta {{ $this->detail()?->venta_id ? '#'.$this->detail()->venta_id : '' }}</x-slot>

        @php($detail = $this->detail())

        @if ($detail)
            <div class="crm-detail-summary">
                <div><span>Cliente</span><strong>{{ $detail->cliente ?: '—' }}</strong></div>
                <div><span>Local</span><strong>{{ $detail->local ?: '—' }}</strong></div>
                <div><span>Comprobante</span><strong>{{ trim(($detail->comprobante_tipo ?? '').' '.($detail->comprobante_serie ?? '').'-'.($detail->comprobante_numero ?? ''), ' -') ?: '—' }}</strong></div>
                <div><span>Fecha</span><strong>{{ $detail->venta_fecha?->format('d/m/Y H:i:s') ?: '—' }}</strong></div>
                <div><span>Total</span><strong>{{ number_format((float) $detail->total, 2) }} {{ $detail->moneda }}</strong></div>
            </div>

            <livewire:ventas.detalle-venta-table :items="$detail->detalles->map(fn ($item) => [
                'descripcion' => $item->descripcion,
                'cantidad' => $item->cantidad,
                'precio' => $item->precio,
                'descuento' => $item->descuento,
                'importe' => $item->importe,
            ])->all()" wire:key="historico-venta-detalle-{{ $detail->venta_id }}" />
        @endif

        <x-slot name="footerActions"><x-filament::button color="gray" wire:click="closeDetail">Cerrar</x-filament::button></x-slot>
    </x-filament::modal>
</x-filament-panels::page>
