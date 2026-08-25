<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section collapsible :collapsed="$hasSearched" class="opm-query-section">
            <x-slot name="heading">Filtros</x-slot>

            <form wire:submit.prevent="search" class="space-y-4">
                {{ $this->form }}

                <x-filament::fieldset label="Fecha">
                    @include('filament.pages.kardex.partials.date-range-picker')
                </x-filament::fieldset>

                <div class="opm-form-actions">
                    <x-filament::button type="submit" icon="heroicon-m-magnifying-glass" wire:loading.attr="disabled" wire:target="search">
                        Consultar
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>

        @if ($hasSearched)
            @php($resumen = $this->resumen())
            <div class="grid grid-cols-3 gap-3">
                <x-filament::section compact class="opm-kpi-card" style="--opm-kpi-color: #64748b">
                    <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Movimientos</span>
                    <p class="text-xl font-semibold text-gray-950 dark:text-white">{{ number_format($resumen['movimientos']) }}</p>
                </x-filament::section>
                <x-filament::section compact class="opm-kpi-card" style="--opm-kpi-color: #16a34a">
                    <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Entradas</span>
                    <p class="text-xl font-semibold text-success-600 dark:text-success-400">{{ number_format((float) $resumen['entradas'], 2) }}</p>
                </x-filament::section>
                <x-filament::section compact class="opm-kpi-card" style="--opm-kpi-color: #dc2626">
                    <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Salidas</span>
                    <p class="text-xl font-semibold text-danger-600 dark:text-danger-400">{{ number_format((float) $resumen['salidas'], 2) }}</p>
                </x-filament::section>
            </div>

            <x-filament::section id="kardex-history-results">
                <x-slot name="heading">Resultados</x-slot>
                @include('filament.pages.kardex.partials.historico-tabla')
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
