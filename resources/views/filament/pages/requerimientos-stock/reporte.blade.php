<x-filament-panels::page>
    @php($sincronizacion = $this->sincronizacionReporteActual())
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
                        Histórico local disponible hasta el <strong>{{ $ultimaFecha }}</strong>. Este módulo no se actualiza solo -- si el rango elegido queda después de esa fecha, presiona "Sincronizar filtro" antes de esperar resultados.
                    @else
                        Aún no hay histórico local sincronizado. Presiona "Sincronizar filtro" para traer datos de Restaurant.
                    @endif
                </p>

                <div class="flex flex-wrap justify-end gap-3">
                    @if (auth()->user()?->hasPermission('requerimientos-stock.reporte.sincronizar'))
                        <x-filament::button type="button" color="gray" icon="heroicon-m-arrow-path" wire:click="sincronizarFiltro" wire:loading.attr="disabled" wire:target="sincronizarFiltro">
                            Sincronizar filtro
                        </x-filament::button>
                    @endif
                    <x-filament::button type="submit" icon="heroicon-m-magnifying-glass" wire:loading.attr="disabled" wire:target="search">
                        Aplicar filtros
                    </x-filament::button>
                </div>
            </form>

            @if($sincronizacion)
                <div @if(in_array($sincronizacion->estado, ['pendiente', 'en_progreso'], true)) wire:poll.3s="refreshSincronizacionReporte" @endif class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                    @php($progreso = $sincronizacion->total_registros > 0 ? min(100, round(($sincronizacion->registros_procesados / $sincronizacion->total_registros) * 100)) : 0)
                    <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <span class="font-medium text-gray-950 dark:text-white">Sincronización #{{ $sincronizacion->id }} · {{ ucfirst(str_replace('_', ' ', $sincronizacion->estado)) }}</span>
                        <span class="text-gray-500 dark:text-gray-400">{{ number_format($sincronizacion->registros_procesados) }} de {{ $sincronizacion->total_registros ?: '—' }} requerimientos</span>
                    </div>
                    <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10"><div class="h-full rounded-full bg-primary-600 transition-all" style="width: {{ $progreso }}%"></div></div>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ $progreso }}% · {{ number_format($sincronizacion->cabeceras_guardadas) }} cabeceras y {{ number_format($sincronizacion->detalles_guardados) }} detalles actualizados.</p>
                    @if($sincronizacion->mensaje_error)<p class="mt-2 text-xs text-danger-600 dark:text-danger-400">{{ $sincronizacion->mensaje_error }}</p>@endif
                </div>
            @endif
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
