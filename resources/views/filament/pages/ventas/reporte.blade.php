<x-filament-panels::page>
    <div class="space-y-6" x-data x-on:sales-results-ready.window="$nextTick(() => document.getElementById('sales-report')?.scrollIntoView({ behavior: 'smooth', block: 'start' }))">
        @include('filament.pages.ventas.partials.filters')

        @if ($hasSearched)
            @php($totals = $this->salesTotals())
            <div id="sales-report" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color: #64748b">
                    <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Ventas encontradas</span>
                    <p class="text-2xl font-semibold text-gray-950 dark:text-white">{{ $salesTotal }}</p>
                </x-filament::section>
                <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color: #d97706">
                    <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Subtotal · página</span>
                    <p class="text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($totals['subtotal'], 2) }}</p>
                </x-filament::section>
                <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color: #64748b">
                    <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Impuestos · página</span>
                    <p class="text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($totals['taxes'], 2) }}</p>
                </x-filament::section>
                <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color: #16a34a">
                    <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Total · página</span>
                    <p class="text-2xl font-semibold text-success-600 dark:text-success-400">{{ number_format($totals['total'], 2) }}</p>
                </x-filament::section>
            </div>

            <x-filament::section>
                <x-slot name="heading">Reporte</x-slot>
                @if ($resultError)
                    <p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ $resultError }}</p>
                @else
                    {{ $this->table }}
                @endif
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
