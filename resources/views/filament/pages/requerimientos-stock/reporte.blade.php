<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section collapsible>
            <x-slot name="heading">Filtros</x-slot>

            <form wire:submit="search" class="space-y-4">
                {{ $this->form }}

                <x-filament::fieldset label="Rango de fecha">
                    @include('filament.components.date-range-picker', [
                        'start' => $data['dateStart'] ?? now()->subDays(30)->toDateString(),
                        'end' => $data['dateEnd'] ?? now()->toDateString(),
                        'preset' => $activeDatePreset,
                        'syncMethod' => 'syncDateRange',
                    ])
                </x-filament::fieldset>

                @php($ultimaFecha = $this->ultimaFechaConDatos())
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    @if($ultimaFecha)
                        Histórico local disponible hasta el <strong>{{ $ultimaFecha }}</strong>. Este reporte consulta solo la copia local -- para traer datos más recientes de Restaurant, usa <a href="{{ \App\Filament\Pages\RequerimientosStock\ExtraccionRequerimientos::getUrl() }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">Extracción de requerimientos</a>.
                    @else
                        Aún no hay histórico local. Ve a <a href="{{ \App\Filament\Pages\RequerimientosStock\ExtraccionRequerimientos::getUrl() }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">Extracción de requerimientos</a> para traer datos de Restaurant.
                    @endif
                </p>

                <div class="flex flex-wrap justify-end gap-3">
                    <x-filament::button type="submit" icon="heroicon-m-magnifying-glass" wire:loading.attr="disabled" wire:target="search">
                        Aplicar filtros
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>

        @if (auth()->user()?->hasPermission('requerimientos-stock.reporte.exportar'))
            <div class="flex flex-wrap justify-end gap-3">
                <x-filament::button color="gray" icon="heroicon-m-arrow-down-tray" wire:click="exportarExcel" wire:loading.attr="disabled" wire:target="exportarExcel">
                    Exportar Excel
                </x-filament::button>
                <x-filament::button color="gray" icon="heroicon-m-document-arrow-down" wire:click="exportarPdf" wire:loading.attr="disabled" wire:target="exportarPdf">
                    Exportar PDF
                </x-filament::button>
            </div>
        @endif

        {{ $this->table }}
    </div>
</x-filament-panels::page>
