<x-filament-panels::page>
    <div
        class="space-y-6"
        x-data
        x-on:stock-results-ready.window="$nextTick(() => document.getElementById('stock-results')?.scrollIntoView({ behavior: 'smooth', block: 'start' }))"
    >
        @if ($gatewayUnavailable)
            <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger">
                <p class="fi-in-text text-sm font-medium text-danger-600 dark:text-danger-400">{{ $filtersError }}</p>
            </x-filament::section>
        @endif

        @if ($resultError)
            <p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ $resultError }}</p>
        @endif

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
</x-filament-panels::page>
