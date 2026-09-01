<x-filament-panels::page>
    <div
        class="space-y-6"
        x-data
        x-on:stock-results-ready.window="$nextTick(() => document.getElementById('stock-results')?.scrollIntoView({ behavior: 'smooth', block: 'start' }))"
    >

        @include('filament.pages.stock.partials.filters-form')

        @if ($hasSearched)
            <div class="grid gap-4 sm:grid-cols-3">
                <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color: #64748b">
                    <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Cantidad de cuadres</span>
                    <p class="text-2xl font-semibold text-gray-950 dark:text-white">{{ $cuadresHeader['totalCuadres'] ?? $cuadresHeader['totalcuadres'] ?? $cuadresTotal }}</p>
                </x-filament::section>
                <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color: #16a34a">
                    <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Sobrevalorización</span>
                    <p class="text-2xl font-semibold text-success-600 dark:text-success-400">{{ number_format((float) ($cuadresHeader['cuadremanual_sobrevalorizacion'] ?? $cuadresHeader['sobrevalorizacion'] ?? 0), 2) }}</p>
                </x-filament::section>
                <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color: #dc2626">
                    <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Pérdida</span>
                    <p class="text-2xl font-semibold text-danger-600 dark:text-danger-400">{{ number_format((float) ($cuadresHeader['cuadremanual_perdida'] ?? $cuadresHeader['perdida'] ?? 0), 2) }}</p>
                </x-filament::section>
            </div>

            <x-filament::section id="stock-results">
                {{ $this->table }}
            </x-filament::section>

            <livewire:stock.maestro-operativo-table :rows="$reportMasterRows" wire:key="stock-maestro-{{ md5(json_encode($reportMasterRows)) }}" />
        @endif
    </div>

    <x-filament::modal id="stock-detail-modal" width="7xl" sticky-header sticky-footer class="crm-detail-modal">
        <x-slot name="heading">
            Detalle de cuadre manual {{ $detail['id'] ?? '' ? '#'.$detail['id'] : '' }}
        </x-slot>

        @if ($detailLoading)
            <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                <x-filament::loading-indicator class="h-4 w-4" />
                Cargando detalle local…
            </div>
        @elseif ($detailError)
            <p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ $detailError }}</p>
        @elseif ($detail)
            <div class="crm-detail-summary">
                <div><span>Responsable</span><strong>{{ $detail['registradoPor'] ?: '—' }}</strong></div>
                <div><span>Local</span><strong>{{ $detail['local'] ?? '—' }}</strong></div>
                <div><span>Registro</span><strong>{{ $detail['fechaRegistro'] ?? '—' }}</strong></div>
                <div><span>Cuadre</span><strong>{{ $detail['fechaCuadre'] ?? '—' }}</strong></div>
                <div><span>Ítems</span><strong>{{ count($detail['items'] ?? []) }}</strong></div>
            </div>

            @if (count($detail['items'] ?? []))
                <livewire:requerimientos-stock.tabla
                    :rows="$this->detailTableRows()"
                    :columns="$this->detailTableColumns()"
                    wire:key="stock-detail-{{ $detail['id'] ?? $detail['cuadremanual_id'] ?? 'actual' }}"
                />
            @else
                <x-filament::empty-state icon="heroicon-o-inbox" heading="Este cuadre no tiene ítems." />
            @endif
        @endif

        <x-slot name="footerActions">
            <x-filament::button color="gray" wire:click="closeDetail">Cerrar</x-filament::button>
        </x-slot>
    </x-filament::modal>
</x-filament-panels::page>
