<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section collapsible :collapsed="true" class="crm-query-section">
            <x-slot name="heading">Filtros</x-slot>

            <form wire:submit="buscar" class="space-y-4">
                {{ $this->form }}

                <div class="flex items-center justify-between">
                    @if(! $this->comparando())
                        <div class="flex items-center gap-2">
                            <x-filament::icon-button icon="heroicon-m-chevron-left" wire:click="diaAnterior" label="Día anterior" />
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ $this->fechaLabel() }}</span>
                            <x-filament::icon-button icon="heroicon-m-chevron-right" wire:click="diaSiguiente" label="Día siguiente" />
                        </div>
                    @else
                        <span></span>
                    @endif

                    <div class="flex items-center gap-2">
                        @if(auth()->user()?->hasPermission('kardex.consolidado-ventas.exportar'))
                            <x-filament::button type="button" color="gray" icon="heroicon-m-arrow-down-tray" wire:click="exportarExcel" wire:loading.attr="disabled" wire:target="exportarExcel">
                                Excel
                            </x-filament::button>
                            <x-filament::button type="button" color="gray" icon="heroicon-m-document-arrow-down" wire:click="exportarPdf" wire:loading.attr="disabled" wire:target="exportarPdf">
                                PDF
                            </x-filament::button>
                        @endif
                        <x-filament::button type="submit" icon="heroicon-m-magnifying-glass" wire:loading.attr="disabled" wire:target="buscar">
                            Consultar
                        </x-filament::button>
                    </div>
                </div>
            </form>
        </x-filament::section>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color: #2563eb">
                <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Unidades vendidas</span>
                <p class="text-xl font-semibold text-primary-600 dark:text-primary-400">{{ number_format($summary['total_unidades'] ?? 0, 0) }}</p>
            </x-filament::section>
            <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color: #d97706">
                <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Productos con venta</span>
                <p class="text-xl font-semibold text-warning-600 dark:text-warning-400">{{ number_format($summary['productos'] ?? 0) }}</p>
            </x-filament::section>
            @if($this->comparando())
                <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color: #8b5cf6">
                    <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Fechas comparadas</span>
                    <p class="text-xl font-semibold text-violet-600 dark:text-violet-400">{{ number_format($summary['fechas'] ?? 0) }}</p>
                </x-filament::section>
            @else
                <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color: #8b5cf6">
                    <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Locales con venta</span>
                    <p class="text-xl font-semibold text-violet-600 dark:text-violet-400">{{ number_format($summary['locales'] ?? 0) }}</p>
                </x-filament::section>
            @endif
        </div>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
