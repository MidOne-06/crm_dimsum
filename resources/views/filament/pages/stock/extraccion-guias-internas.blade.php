@php($resumen = $this->resumenGeneral())
@php($activas = $this->extraccionesActivas())

<x-filament-panels::page>
    <div class="space-y-4" x-data="{ tab: 'nueva' }">
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
            <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color:#64748b"><span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Guías guardadas</span><p class="text-xl font-semibold text-gray-950 dark:text-white">{{ number_format($resumen['guias']) }}</p></x-filament::section>
            <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color:#3e86d8"><span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Detalles guardados</span><p class="text-xl font-semibold text-gray-950 dark:text-white">{{ number_format($resumen['detalles']) }}</p></x-filament::section>
            <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color:#3e86d8"><span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Corridas totales</span><p class="text-xl font-semibold text-gray-950 dark:text-white">{{ number_format($resumen['corridas']) }}</p></x-filament::section>
            <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color:#dc2626"><span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Corridas fallidas</span><p class="text-xl font-semibold text-danger-600 dark:text-danger-400">{{ number_format($resumen['fallidas']) }}</p></x-filament::section>
            <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color:#16a34a"><span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Cobertura {{ $coverageYear }}</span><p class="text-xl font-semibold text-success-600 dark:text-success-400">{{ $resumen['coveragePercent'] }}%</p></x-filament::section>
        </div>

        <x-filament::tabs contained>
            <x-filament::tabs.item tag="button" icon="heroicon-o-circle-stack" alpine-active="tab === 'nueva'" x-on:click="tab = 'nueva'">Nueva extracción</x-filament::tabs.item>
            <x-filament::tabs.item tag="button" icon="heroicon-o-calendar-days" alpine-active="tab === 'cobertura'" x-on:click="tab = 'cobertura'">Cobertura</x-filament::tabs.item>
            <x-filament::tabs.item tag="button" icon="heroicon-o-clock" alpine-active="tab === 'historial'" x-on:click="tab = 'historial'">Historial <x-slot:badge>{{ $resumen['corridas'] }}</x-slot:badge></x-filament::tabs.item>
        </x-filament::tabs>

        <div x-show="tab === 'nueva'" class="space-y-4">
            <div class="flex justify-end">
                {{-- Ya no se deshabilita: encolar mientras hay otra activa es seguro -- el despachador programado las toma de a una, la más vieja primero. --}}
                <x-filament::button wire:click="abrirFiltrosExtraccion" icon="heroicon-o-adjustments-horizontal">
                    {{ $activas->isEmpty() ? 'Configurar extracción' : 'Encolar otra extracción' }}
                </x-filament::button>
            </div>

            @if($activas->isNotEmpty())
                <div wire:poll.3s="refreshExtraccion" class="space-y-3">
                    @foreach($activas as $run)
                        @php($estancada = $this->estaEstancada($run))
                        <x-filament::section>
                            <x-slot name="heading">
                                Extracción #{{ $run->id }}
                                <span class="crm-status">{{ ucfirst(str_replace('_',' ',$run->estado)) }}</span>
                                @if($estancada)
                                    <span class="ml-2 inline-flex items-center gap-1 rounded-full bg-danger-50 px-2 py-0.5 text-xs font-medium text-danger-600 dark:bg-danger-500/10 dark:text-danger-400">
                                        <x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5" />
                                        Sin avance desde {{ $run->updated_at->diffForHumans() }}
                                    </span>
                                @endif
                            </x-slot>
                            <x-slot name="headerEnd">
                                @if($run->estado === 'pendiente' && ! $estancada)
                                    <x-filament::button color="gray" icon="heroicon-m-trash" size="sm" wire:click="eliminarDeCola({{ $run->id }})" wire:confirm="¿Eliminar la extracción #{{ $run->id }} de la cola? Todavía no arrancó.">Quitar de la cola</x-filament::button>
                                @else
                                    <x-filament::button color="danger" icon="heroicon-m-stop-circle" size="sm" wire:click="cancelarExtraccion({{ $run->id }})" wire:confirm="¿Detener la extracción #{{ $run->id }}? El avance guardado hasta ahora se conserva y se puede reanudar después.">
                                        {{ $estancada ? 'Cancelar (atascada)' : 'Detener' }}
                                    </x-filament::button>
                                @endif
                            </x-slot>

                            @if($estancada)
                                <p class="mb-3 text-sm text-danger-600 dark:text-danger-400">Esta extracción dejó de reportar avance hace más de 15 minutos -- casi siempre significa que el proceso que la corría ya no existe (reinicio del servidor u otra interrupción), no que siga trabajando en segundo plano. Puedes cancelarla con seguridad: el avance ya guardado se conserva y queda reanudable.</p>
                            @endif

                            @if($run->estado === 'pendiente')
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    @if($loop->last)
                                        En cola -- arranca en menos de un minuto.
                                    @else
                                        En cola -- espera su turno detrás de las demás.
                                    @endif
                                </p>
                            @else
                                @php($progreso = $run->paginas_total > 0 ? min(100, round(($run->paginas_procesadas / $run->paginas_total) * 100)) : 0)
                                <div class="mb-3"><div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10"><div class="h-full rounded-full {{ $estancada ? 'bg-danger-500' : 'bg-primary-600' }} transition-all" style="width:{{ $progreso }}%"></div></div><p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $progreso }}% · {{ $run->paginas_procesadas }} de {{ $run->paginas_total ?: '—' }} páginas procesadas</p></div>
                                <div class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4"><div><span class="block text-gray-500 dark:text-gray-400">Cabeceras</span><strong class="text-gray-950 dark:text-white">{{ $run->cabeceras_guardadas }}</strong></div><div><span class="block text-gray-500 dark:text-gray-400">Detalles</span><strong class="text-gray-950 dark:text-white">{{ $run->detalles_guardados }}</strong></div><div><span class="block text-gray-500 dark:text-gray-400">Eliminadas</span><strong class="text-gray-950 dark:text-white">{{ $run->cabeceras_eliminadas }}</strong></div><div><span class="block text-gray-500 dark:text-gray-400">Fallidas</span><strong class="text-danger-600 dark:text-danger-400">{{ $run->errores }}</strong></div></div>
                            @endif
                        </x-filament::section>
                    @endforeach
                </div>
            @endif
        </div>

        <div x-show="tab === 'cobertura'" x-cloak>@include('filament.pages.stock.partials.extraccion-guias-cobertura')</div>
        <div x-show="tab === 'historial'" x-cloak><x-filament::section><x-slot name="heading">Historial de extracciones</x-slot>{{ $this->table }}</x-filament::section></div>

        <x-filament::modal id="filtros-extraccion-guias" width="5xl" sticky-header sticky-footer>
            <x-slot name="heading">Filtros de extracción</x-slot>

            @if($activas->isNotEmpty())
                <div class="mb-4 flex items-start gap-2 rounded-lg bg-info-50 p-3 text-sm text-info-700 dark:bg-info-500/10 dark:text-info-400">
                    <x-heroicon-o-information-circle class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>Ya hay {{ $activas->count() }} {{ $activas->count() === 1 ? 'extracción' : 'extracciones' }} en curso o en cola. Esta se agrega al final y arranca cuando le toque su turno -- no hace falta esperar.</span>
                </div>
            @endif

            <form id="filtros-extraccion-guias-form" wire:submit.prevent="iniciarExtraccion" class="space-y-5">
                <div class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-4">
                    <div class="md:col-span-2 xl:col-span-4">
                        <span class="mb-1.5 block text-sm font-medium leading-6 text-gray-950 dark:text-white">Rango de fecha</span>
                        @include('filament.components.date-range-picker', ['start' => $data['dateStart'] ?? now()->subDays(30)->toDateString(), 'end' => $data['dateEnd'] ?? now()->toDateString(), 'preset' => $activeDatePreset, 'syncMethod' => 'syncDateRange'])
                    </div>
                    <div class="md:col-span-2 xl:col-span-4">
                        {{ $this->form }}
                    </div>
                </div>
                @if($resultError)<p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ $resultError }}</p>@endif
            </form>

            <x-slot name="footerActions">
                <x-filament::button color="gray" wire:click="cerrarFiltrosExtraccion">Cancelar</x-filament::button>
                {{--
                    El prop de x-filament::button que escribe el atributo
                    HTML form="..." es `form-id`, no `form` (ese otro solo
                    alimenta el indicador de carga interno) -- con `form=`
                    este botón quedaba sin ningún <form> asociado y el clic
                    no disparaba nada, nunca.
                --}}
                <x-filament::button type="submit" form-id="filtros-extraccion-guias-form" icon="heroicon-m-circle-stack">Iniciar extracción</x-filament::button>
            </x-slot>
        </x-filament::modal>
    </div>
</x-filament-panels::page>
