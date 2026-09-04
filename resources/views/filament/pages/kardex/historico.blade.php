<x-filament-panels::page>
    <div class="space-y-6">
        <div class="flex justify-end">
            <x-filament::button type="button" color="gray" icon="heroicon-o-adjustments-horizontal" wire:click="abrirFiltros">
                Filtros
            </x-filament::button>
        </div>

        @php($resumen = $this->resumen())
        <div class="grid grid-cols-3 gap-3">
            <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color: #64748b">
                <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Movimientos</span>
                <p class="text-xl font-semibold text-gray-950 dark:text-white">{{ number_format($resumen['movimientos']) }}</p>
            </x-filament::section>
            <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color: #16a34a">
                <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Entradas</span>
                <p class="text-xl font-semibold text-success-600 dark:text-success-400">{{ number_format((float) $resumen['entradas'], 2) }}</p>
            </x-filament::section>
            <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color: #dc2626">
                <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Salidas</span>
                <p class="text-xl font-semibold text-danger-600 dark:text-danger-400">{{ number_format((float) $resumen['salidas'], 2) }}</p>
            </x-filament::section>
        </div>

        {{ $this->table }}

        <x-filament::modal id="filtros-kardex-historico" width="4xl" sticky-header sticky-footer>
            <x-slot name="heading">Filtros</x-slot>

            <form id="filtros-kardex-historico-form" wire:submit="search" class="space-y-4">
                {{ $this->form }}

                <x-filament::fieldset label="Rango de fecha" class="crm-filter-date">
                    @include('filament.pages.kardex.partials.date-range-picker')
                </x-filament::fieldset>
            </form>

            <x-slot name="footerActions">
                <x-filament::button color="gray" wire:click="cerrarFiltros">Cancelar</x-filament::button>
                <x-filament::button
                    type="button"
                    wire:click="search"
                    x-on:click="$dispatch('close-modal', { id: 'filtros-kardex-historico' })"
                    icon="heroicon-m-magnifying-glass"
                    wire:loading.attr="disabled"
                    wire:target="search"
                >
                    Consultar
                </x-filament::button>
            </x-slot>
        </x-filament::modal>
    </div>
</x-filament-panels::page>
