<x-filament-panels::page>
    <div class="space-y-6" x-data x-on:sales-results-ready.window="$nextTick(() => document.getElementById('sales-results')?.scrollIntoView({ behavior: 'smooth', block: 'start' }))">
        @include('filament.pages.ventas.partials.filters')

        @if ($hasSearched)
            <x-filament::section id="sales-results">
                <x-slot name="heading">Resultados</x-slot>

                @if ($resultError)
                    <p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ $resultError }}</p>
                @else
                    {{ $this->table }}
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
            <div class="crm-detail-summary">
                <div><span>Cliente</span><strong>{{ $detail['cliente']['nombre'] ?: '—' }}</strong></div>
                <div><span>Local</span><strong>{{ $detail['local'] ?: '—' }}</strong></div>
                <div><span>Comprobante</span><strong>{{ trim(($detail['comprobante']['tipo'] ?? '').' '.($detail['comprobante']['serie'] ?? '').'-'.($detail['comprobante']['numero'] ?? ''), ' -') ?: '—' }}</strong></div>
                <div><span>Fecha</span><strong>{{ $detail['fecha'] ?: '—' }}</strong></div>
                <div><span>Total</span><strong>{{ number_format((float) ($detail['total'] ?? 0), 2) }} {{ $detail['moneda'] ?? '' }}</strong></div>
            </div>

            <livewire:ventas.detalle-venta-table :items="$detail['items'] ?? []" wire:key="consulta-venta-detalle-{{ $detail['id'] ?? 'sin-id' }}" />
        @endif

        <x-slot name="footerActions"><x-filament::button color="gray" wire:click="closeDetail">Cerrar</x-filament::button></x-slot>
    </x-filament::modal>
</x-filament-panels::page>
