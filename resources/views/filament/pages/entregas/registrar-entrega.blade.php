<x-filament-panels::page>
    @php($locales = $this->localesDisponibles())
    @php($pendientes = collect($locales)->where('ya_entregado', false)->count())

    <div class="mx-auto w-full max-w-xl space-y-3">
        <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ now()->locale('es')->isoFormat('dddd D [de] MMMM') }} &middot;
                <span class="font-semibold text-gray-950 dark:text-white">{{ $pendientes }}</span> entrega(s) pendiente(s) hoy
            </p>
        </div>

        @forelse($locales as $fila)
            <div @class([
                'fi-section rounded-xl p-4 shadow-sm ring-1 dark:bg-gray-900',
                'bg-white ring-gray-950/5 dark:ring-white/10' => ! $fila['ya_entregado'],
                'bg-success-50 ring-success-600/20 dark:bg-success-500/10' => $fila['ya_entregado'],
            ])>
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="truncate text-base font-semibold text-gray-950 dark:text-white">{{ $fila['local_nombre'] }}</p>
                        <div class="mt-1 flex flex-wrap items-center gap-1.5">
                            <span @class([
                                'fi-badge inline-flex items-center rounded-md px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset',
                                'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-400/10 dark:text-success-400' => $fila['rol'] === 'titular',
                                'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-400/10 dark:text-gray-400' => $fila['rol'] === 'suplente',
                            ])>{{ ucfirst($fila['rol']) }}</span>
                            @if($fila['es_reemplazo'])
                                <span class="fi-badge inline-flex items-center rounded-md bg-warning-50 px-1.5 py-0.5 text-xs font-medium text-warning-700 ring-1 ring-inset ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400">
                                    Reemplazo{{ $fila['motivo_auto'] ? ' · '.$fila['motivo_auto'] : '' }}
                                </span>
                            @endif
                        </div>
                    </div>
                    <div class="shrink-0">
                        @if($fila['ya_entregado'])
                            <span class="inline-flex items-center gap-1 text-sm font-medium text-success-700 dark:text-success-400">
                                <x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5" />
                                Entregado
                            </span>
                        @else
                            <x-filament::button
                                size="sm"
                                color="success"
                                icon="heroicon-o-check-circle"
                                wire:click="mountAction('entregar', @js([
                                    'local_id' => $fila['local_id'],
                                    'local_nombre' => $fila['local_nombre'],
                                    'es_reemplazo' => $fila['es_reemplazo'],
                                    'motivo_auto' => $fila['motivo_auto'],
                                ]))"
                            >
                                Entregar
                            </x-filament::button>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="fi-section rounded-xl bg-white p-6 text-center shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <p class="text-sm text-gray-600 dark:text-gray-400">No tenés locales asignados para entregar hoy.</p>
            </div>
        @endforelse

        <button
            type="button"
            wire:click="toggleCubrir"
            class="w-full rounded-lg border border-dashed border-gray-300 px-4 py-2.5 text-sm text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800"
        >
            {{ $cubrirDeMasModos ? 'Ocultar locales de suplente sin ausencia del titular' : 'Cubrir de todos modos (el titular no vino y no está cargada su ausencia)' }}
        </button>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
