@php($extraccion = $this->extraccionActual())

@if ($extraccion)
    <div @if (in_array($extraccion->estado, ['pendiente', 'en_progreso'], true)) wire:poll.3s="refreshExtraccion" @endif>
    <x-filament::section>
        <x-slot name="heading">
            Extracción #{{ $extraccion->id }}
            <span class="crm-status">{{ ucfirst(str_replace('_', ' ', $extraccion->estado)) }}</span>
        </x-slot>

        @if (in_array($extraccion->estado, ['pendiente', 'en_progreso'], true) && auth()->user()?->hasPermission('kardex.extraccion.anular'))
            <x-slot name="afterHeader">
                <x-filament::button
                    color="danger"
                    size="sm"
                    icon="heroicon-o-x-circle"
                    wire:click="anularExtraccion"
                    wire:confirm="¿Anular esta extracción? Los locales que aún no se hayan procesado se quedarán sin guardar."
                >
                    Anular extracción
                </x-filament::button>
            </x-slot>
        @endif

        @php($conteos = $extraccion->countsForDisplay())

        @if ($extraccion->estado === 'en_progreso' || $extraccion->estado === 'completado')
            <div class="mb-3">
                <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                    <div class="h-full rounded-full bg-primary-600 transition-all" style="width: {{ $extraccion->progreso }}%"></div>
                </div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $extraccion->progreso }}% · {{ $conteos['procesados'] + $conteos['fallidos'] }} de {{ $extraccion->locales_total ?? '—' }} locales procesados</p>
            </div>
        @endif

        <div class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
            <div><span class="block text-gray-500 dark:text-gray-400">Movimientos guardados</span><strong class="text-gray-950 dark:text-white">{{ $conteos['movimientos'] }}</strong></div>
            <div><span class="block text-gray-500 dark:text-gray-400">Locales procesados</span><strong class="text-gray-950 dark:text-white">{{ $conteos['procesados'] }}</strong></div>
            <div><span class="block text-gray-500 dark:text-gray-400">Locales fallidos</span><strong class="text-danger-600 dark:text-danger-400">{{ $conteos['fallidos'] }}</strong></div>
            <div><span class="block text-gray-500 dark:text-gray-400">Duración</span><strong class="text-gray-950 dark:text-white">{{ $extraccion->duracion ?? '—' }}</strong></div>
        </div>

        @if (in_array($extraccion->estado, ['fallido', 'cancelado'], true) && $extraccion->mensaje_error)
            <p class="mt-3 text-sm font-medium text-danger-600 dark:text-danger-400">{{ $extraccion->mensaje_error }}</p>
        @endif

        <div class="mt-4">
            <livewire:kardex.extraccion-locales-table :extraccion-id="$extraccion->id" :key="'kardex-extraccion-locales-'.$extraccion->id" />
        </div>
    </x-filament::section>
    </div>
@endif
