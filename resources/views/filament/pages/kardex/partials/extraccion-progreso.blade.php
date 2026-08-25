@php($extraccion = $this->extraccionActual())

@if ($extraccion)
    <div @if (in_array($extraccion->estado, ['pendiente', 'en_progreso'], true)) wire:poll.3s="refreshExtraccion" @endif>
    <x-filament::section>
        <x-slot name="heading">
            Extracción #{{ $extraccion->id }}
            <span class="opm-status">{{ ucfirst(str_replace('_', ' ', $extraccion->estado)) }}</span>
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

        @if ($extraccion->estado === 'en_progreso' || $extraccion->estado === 'completado')
            <div class="mb-3">
                <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                    <div class="h-full rounded-full bg-primary-600 transition-all" style="width: {{ $extraccion->progreso }}%"></div>
                </div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $extraccion->progreso }}% · {{ $extraccion->locales_procesados + $extraccion->locales_fallidos }} de {{ $extraccion->locales_total ?? '—' }} locales procesados</p>
            </div>
        @endif

        <div class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
            <div><span class="block text-gray-500 dark:text-gray-400">Movimientos guardados</span><strong class="text-gray-950 dark:text-white">{{ $extraccion->movimientos_guardados }}</strong></div>
            <div><span class="block text-gray-500 dark:text-gray-400">Locales procesados</span><strong class="text-gray-950 dark:text-white">{{ $extraccion->locales_procesados }}</strong></div>
            <div><span class="block text-gray-500 dark:text-gray-400">Locales fallidos</span><strong class="text-danger-600 dark:text-danger-400">{{ $extraccion->locales_fallidos }}</strong></div>
            <div><span class="block text-gray-500 dark:text-gray-400">Duración</span><strong class="text-gray-950 dark:text-white">{{ $extraccion->duracion ?? '—' }}</strong></div>
        </div>

        @if (in_array($extraccion->estado, ['fallido', 'cancelado'], true) && $extraccion->mensaje_error)
            <p class="mt-3 text-sm font-medium text-danger-600 dark:text-danger-400">{{ $extraccion->mensaje_error }}</p>
        @endif

        @if ($extraccion->locales->isNotEmpty())
            <div class="mt-4 opm-stock-table">
                <div class="fi-ta-content overflow-x-auto">
                    <table class="fi-ta-table w-full text-start">
                        <thead>
                            <tr>
                                <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Local</span></th>
                                <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Estado</span></th>
                                <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Movimientos</span></th>
                                <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Error</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($extraccion->locales as $local)
                                <tr class="fi-ta-row">
                                    <td class="fi-ta-cell"><div class="px-3 py-2 text-sm text-gray-950 dark:text-white">{{ $local->local_nombre ?? $local->local_id }}</div></td>
                                    <td class="fi-ta-cell"><div class="px-3 py-2"><span class="opm-status">{{ ucfirst(str_replace('_', ' ', $local->estado)) }}</span></div></td>
                                    <td class="fi-ta-cell"><div class="opm-table-number px-3 py-2 text-sm text-gray-950 dark:text-white">{{ $local->movimientos_guardados }}</div></td>
                                    <td class="fi-ta-cell"><div class="px-3 py-2 text-sm text-danger-600 dark:text-danger-400">{{ $local->mensaje_error ?? '—' }}</div></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </x-filament::section>
    </div>
@endif
