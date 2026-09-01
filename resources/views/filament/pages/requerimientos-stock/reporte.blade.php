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
                        Histórico local disponible hasta el <strong>{{ $ultimaFecha }}</strong>. Este filtro se sincroniza solo contra Restaurant al buscar -- no hace falta ningún botón aparte.
                    @else
                        Aún no hay histórico local. Al aplicar filtros se sincroniza solo contra Restaurant.
                    @endif
                </p>

                <div class="flex flex-wrap justify-end gap-3">
                    <x-filament::button type="submit" icon="heroicon-m-magnifying-glass" wire:loading.attr="disabled" wire:target="search">
                        Aplicar filtros
                    </x-filament::button>
                </div>
            </form>

            {{-- Solo se muestra mientras hay una sincronización real en curso para ESTE filtro -- no es un control, es un indicador. Una sola corrida a la vez para todo el módulo (ver autoSincronizar()), así que nunca hay varias barras compitiendo. --}}
            @if($sincronizacion && in_array($sincronizacion->estado, ['pendiente', 'en_progreso'], true))
                <div wire:poll.3s="refreshSincronizacionReporte" class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                    @php($progreso = $sincronizacion->total_registros > 0 ? min(100, round(($sincronizacion->registros_procesados / $sincronizacion->total_registros) * 100)) : 0)
                    <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <span class="font-medium text-gray-950 dark:text-white">Actualizando datos de Restaurant…</span>
                        <span class="text-gray-500 dark:text-gray-400">{{ number_format($sincronizacion->registros_procesados) }} de {{ $sincronizacion->total_registros ?: '—' }} requerimientos</span>
                    </div>
                    <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10"><div class="h-full rounded-full bg-primary-600 transition-all" style="width: {{ $progreso }}%"></div></div>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ $progreso }}% · el reporte de abajo sigue mostrando lo último ya guardado y se refresca solo al terminar.</p>
                </div>
            @elseif($sincronizacion && $sincronizacion->estado === 'fallido')
                <p class="mt-4 text-xs text-danger-600 dark:text-danger-400">La última actualización contra Restaurant falló: {{ $sincronizacion->mensaje_error }}</p>
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
