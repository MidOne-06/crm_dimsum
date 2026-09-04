<x-filament-panels::page>
    <div class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                @if(! $this->comparando())
                    <x-filament::icon-button icon="heroicon-m-chevron-left" wire:click="diaAnterior" label="Día anterior" />
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ $this->fechaLabel() }}</span>
                    <x-filament::icon-button icon="heroicon-m-chevron-right" wire:click="diaSiguiente" label="Día siguiente" />
                @endif
            </div>

            <div class="flex items-center gap-2">
                <x-filament::button type="button" color="gray" icon="heroicon-o-adjustments-horizontal" wire:click="abrirFiltros">
                    Filtros
                </x-filament::button>
                @if(auth()->user()?->hasPermission('kardex.consolidado-ventas.exportar'))
                    <x-filament::button type="button" color="gray" icon="heroicon-m-arrow-down-tray" wire:click="exportarExcel" wire:loading.attr="disabled" wire:target="exportarExcel">
                        Excel
                    </x-filament::button>
                    <x-filament::button type="button" color="gray" icon="heroicon-m-document-arrow-down" wire:click="exportarPdf" wire:loading.attr="disabled" wire:target="exportarPdf">
                        PDF
                    </x-filament::button>
                @endif
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color: #2563eb">
                <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Unidades vendidas</span>
                <p class="text-xl font-semibold text-primary-600 dark:text-primary-400">{{ number_format($summary['total_unidades'] ?? 0, 0) }}</p>
            </x-filament::section>
            <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color: #d97706">
                <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Productos con venta</span>
                <p class="text-xl font-semibold text-warning-600 dark:text-warning-400">{{ number_format($summary['productos'] ?? 0) }}</p>
            </x-filament::section>
            <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color: #8b5cf6">
                <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Locales{{ $this->comparando() ? '' : ' con venta' }}</span>
                <p class="text-xl font-semibold text-violet-600 dark:text-violet-400">{{ number_format($summary['locales'] ?? 0) }}</p>
            </x-filament::section>
            @if($this->comparando())
                <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color: #0891b2">
                    <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Fechas{{ $this->sumarFechas() ? ' sumadas' : ' comparadas' }}</span>
                    <p class="text-xl font-semibold text-cyan-600 dark:text-cyan-400">{{ number_format($summary['fechas'] ?? 0) }}</p>
                </x-filament::section>
            @endif
        </div>

        @if($this->comparando() && ! $this->sumarFechas() && ($summary['locales'] ?? 0) > 6)
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Mostrando {{ number_format($summary['locales']) }} locales x {{ number_format($summary['fechas'] ?? 0) }} fechas -- si la tabla queda muy ancha, elegí locales puntuales en el filtro "Locales" para acotarla, o activá "Sumar las fechas" para una columna por local.
            </p>
        @endif

        {{ $this->table }}

        <x-filament::modal id="filtros-consolidado-ventas" width="4xl" sticky-header sticky-footer>
            <x-slot name="heading">Filtros</x-slot>

            <form id="filtros-consolidado-ventas-form" wire:submit="buscar" class="space-y-4">
                {{ $this->form }}
            </form>

            <x-slot name="footerActions">
                <x-filament::button color="gray" wire:click="cerrarFiltros">Cancelar</x-filament::button>
                <x-filament::button
                    type="button"
                    wire:click="buscar"
                    x-on:click="$dispatch('close-modal', { id: 'filtros-consolidado-ventas' })"
                    icon="heroicon-m-magnifying-glass"
                    wire:loading.attr="disabled"
                    wire:target="buscar"
                >
                    <span wire:loading.remove wire:target="buscar">Aplicar filtros</span>
                    <span wire:loading.flex wire:target="buscar" class="items-center gap-2"><x-filament::loading-indicator class="h-4 w-4" /> Aplicando</span>
                </x-filament::button>
            </x-slot>
        </x-filament::modal>
    </div>
</x-filament-panels::page>
