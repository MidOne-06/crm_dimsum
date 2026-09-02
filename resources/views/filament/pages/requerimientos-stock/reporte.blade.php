<x-filament-panels::page>
    <div class="space-y-6">
        <div class="flex flex-wrap justify-end gap-3">
            <x-filament::button wire:click="abrirFiltrosReporte" icon="heroicon-o-adjustments-horizontal">
                Filtros
            </x-filament::button>
            @if (auth()->user()?->hasPermission('requerimientos-stock.reporte.exportar'))
                <x-filament::button color="gray" icon="heroicon-m-arrow-down-tray" wire:click="exportarExcel" wire:loading.attr="disabled" wire:target="exportarExcel">
                    Exportar Excel
                </x-filament::button>
                <x-filament::button color="gray" icon="heroicon-m-document-arrow-down" wire:click="exportarPdf" wire:loading.attr="disabled" wire:target="exportarPdf">
                    Exportar PDF
                </x-filament::button>
            @endif
        </div>

        <div class="relative" wire:loading.class="pointer-events-none" wire:target="search">
            <div
                wire:loading.flex
                wire:target="search"
                class="absolute inset-0 z-20 items-center justify-center rounded-xl bg-white/70 backdrop-blur-[1px] dark:bg-gray-950/70"
                role="status"
                aria-live="polite"
            >
                <div class="flex items-center gap-2 rounded-lg bg-white px-3 py-2 text-sm font-medium shadow-sm ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10">
                    <x-filament::loading-indicator class="h-5 w-5 text-primary-600 dark:text-primary-400" />
                    <span>Actualizando matriz</span>
                </div>
            </div>

            <div wire:loading.class="opacity-40" wire:target="search">
                {{ $this->table }}
            </div>
        </div>

        <x-filament::modal id="filtros-reporte-requerimientos" width="5xl" sticky-header sticky-footer>
            <x-slot name="heading">Filtros de reporte</x-slot>

            <form id="filtros-reporte-requerimientos-form" wire:submit="search" class="space-y-5">
                <div class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-4">
                    <label class="block md:col-span-1 xl:col-span-2">
                        <span class="mb-1.5 block text-sm font-medium leading-6 text-gray-950 dark:text-white">Desde</span>
                        <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="data.dateStart" /></x-filament::input.wrapper>
                    </label>
                    <label class="block md:col-span-1 xl:col-span-2">
                        <span class="mb-1.5 block text-sm font-medium leading-6 text-gray-950 dark:text-white">Hasta</span>
                        <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="data.dateEnd" /></x-filament::input.wrapper>
                    </label>
                    <div class="md:col-span-2 xl:col-span-4">{{ $this->form }}</div>
                </div>
            </form>

            <x-slot name="footerActions">
                <x-filament::button color="gray" wire:click="cerrarFiltrosReporte">Cancelar</x-filament::button>
                <x-filament::button type="button" wire:click="search" x-on:click="$dispatch('close-modal', { id: 'filtros-reporte-requerimientos' })" icon="heroicon-m-magnifying-glass" wire:loading.attr="disabled" wire:target="search">
                    <span wire:loading.remove wire:target="search">Aplicar filtros</span>
                    <span wire:loading.flex wire:target="search" class="items-center gap-2"><x-filament::loading-indicator class="h-4 w-4" /> Aplicando</span>
                </x-filament::button>
            </x-slot>
        </x-filament::modal>
    </div>
</x-filament-panels::page>
