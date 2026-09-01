<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section collapsible :collapsed="true" class="crm-query-section">
            <x-slot name="heading">Cálculo de promedios</x-slot>

            <form wire:submit="search" class="space-y-4">
                {{ $this->form }}

                <x-filament::fieldset label="Rango de fecha" class="crm-filter-date">
                    @include('filament.pages.kardex.partials.date-range-picker')
                </x-filament::fieldset>

                <div class="flex justify-end">
                    <x-filament::button type="submit" icon="heroicon-m-calculator" wire:loading.attr="disabled" wire:target="search">
                        Calcular promedios
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>

        @if (($summary['stockout_days'] ?? 0) > 0)
            <div class="flex items-center gap-2 text-sm font-medium text-warning-700 dark:text-warning-300">
                <x-filament::icon icon="heroicon-m-exclamation-triangle" class="size-5" />
                <span>Posibles quiebres: {{ number_format($summary['stockout_days']) }}</span>
                <x-filament::icon
                    icon="heroicon-m-information-circle"
                    class="size-4 cursor-help"
                    x-tooltip="{ content: 'Días producto/local cuya venta dejó stock en cero. Se conservan en el promedio para no ocultar demanda.', theme: $store.theme }"
                />
            </div>
        @endif

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color: #2563eb">
                <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $this->averageTotalLabel() }}</span>
                <p class="text-xl font-semibold text-primary-600 dark:text-primary-400">{{ number_format($summary['promedio_total'] ?? 0, 0) }}</p>
            </x-filament::section>
            <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color: #16a34a">
                <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Días considerados</span>
                <p class="text-xl font-semibold text-success-600 dark:text-success-400">{{ number_format($summary['denominator'] ?? 0) }}</p>
            </x-filament::section>
            <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color: #d97706">
                <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Productos con venta</span>
                <p class="text-xl font-semibold text-warning-600 dark:text-warning-400">{{ number_format($summary['productos'] ?? 0) }}</p>
            </x-filament::section>
            <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color: #8b5cf6">
                <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Locales con venta</span>
                <p class="text-xl font-semibold text-violet-600 dark:text-violet-400">{{ number_format($summary['locales'] ?? 0) }}</p>
            </x-filament::section>
        </div>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
