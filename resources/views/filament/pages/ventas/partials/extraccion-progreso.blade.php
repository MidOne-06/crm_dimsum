@php($extraccion = $this->extraccionActual())

@if ($extraccion)
    <div @if (in_array($extraccion->estado, ['pendiente', 'en_progreso'], true)) wire:poll.3s="refreshExtraccion" @endif>
    <x-filament::section>
        <x-slot name="heading">
            Extracción #{{ $extraccion->id }}
            <span class="crm-status">{{ ucfirst(str_replace('_', ' ', $extraccion->estado)) }}</span>
        </x-slot>

        @if ($extraccion->estado === 'en_progreso' || $extraccion->estado === 'completado')
            <div class="mb-3">
                <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                    <div class="h-full rounded-full bg-primary-600 transition-all" style="width: {{ $extraccion->progreso }}%"></div>
                </div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $extraccion->progreso }}% · {{ $extraccion->ventas_procesadas }} de {{ $extraccion->ventas_total_estimado ?? '—' }} ventas procesadas</p>
            </div>
        @endif

        <div class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
            <div><span class="block text-gray-500 dark:text-gray-400">Guardadas</span><strong class="text-gray-950 dark:text-white">{{ $extraccion->ventas_guardadas }}</strong></div>
            <div><span class="block text-gray-500 dark:text-gray-400">Ítems guardados</span><strong class="text-gray-950 dark:text-white">{{ $extraccion->items_guardados }}</strong></div>
            <div><span class="block text-gray-500 dark:text-gray-400">Fallidas</span><strong class="text-danger-600 dark:text-danger-400">{{ $extraccion->ventas_fallidas }}</strong></div>
            <div><span class="block text-gray-500 dark:text-gray-400">Duración</span><strong class="text-gray-950 dark:text-white">{{ $extraccion->duracion ?? '—' }}</strong></div>
        </div>

        @if ($extraccion->estado === 'fallido' && $extraccion->mensaje_error)
            <p class="mt-3 text-sm font-medium text-danger-600 dark:text-danger-400">{{ $extraccion->mensaje_error }}</p>
        @endif
    </x-filament::section>
    </div>
@endif
