@php($resumen = $this->resumenGeneral())
@php($extraccion = $this->extraccionActual())

<x-filament-panels::page>
    <div class="space-y-4" x-data="{ tab: 'nueva' }">
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-5">
            <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color:#64748b"><span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Requerimientos guardados</span><p class="text-xl font-semibold text-gray-950 dark:text-white">{{ number_format($resumen['requerimientos']) }}</p></x-filament::section>
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
                <x-filament::button wire:click="abrirFiltrosExtraccion" icon="heroicon-o-adjustments-horizontal" :disabled="$this->hayExtraccionEnProgreso()">
                    {{ $this->hayExtraccionEnProgreso() ? 'Extracción en progreso' : 'Configurar extracción' }}
                </x-filament::button>
            </div>

            @if($extraccion || $esperandoExtraccion)
                <div @if($esperandoExtraccion || ($extraccion && in_array($extraccion->estado,['pendiente','en_progreso'],true))) wire:poll.3s="refreshExtraccion" @endif>
                    @if($extraccion)
                    <x-filament::section>
                        <x-slot name="heading">Extracción #{{ $extraccion->id }} <span class="crm-status">{{ ucfirst(str_replace('_',' ',$extraccion->estado)) }}</span></x-slot>
                        @if(in_array($extraccion->estado,['pendiente','en_progreso'],true))
                            <x-slot name="headerEnd">
                                <x-filament::button color="danger" icon="heroicon-m-stop-circle" size="sm" wire:click="cancelarExtraccion" wire:confirm="¿Detener la extracción #{{ $extraccion->id }}? El avance guardado hasta ahora se conserva y se puede reanudar después.">Detener</x-filament::button>
                            </x-slot>
                        @endif
                        @php($progreso = $extraccion->total_registros > 0 ? min(100, round(($extraccion->registros_procesados / $extraccion->total_registros) * 100)) : 0)
                        <div class="mb-3"><div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10"><div class="h-full rounded-full bg-primary-600 transition-all" style="width:{{ $progreso }}%"></div></div><p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $progreso }}% · {{ $extraccion->registros_procesados }} de {{ $extraccion->total_registros ?: '—' }} requerimientos procesados</p></div>
                        <div class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-3"><div><span class="block text-gray-500 dark:text-gray-400">Guardados</span><strong class="text-gray-950 dark:text-white">{{ $extraccion->cabeceras_guardadas }}</strong></div><div><span class="block text-gray-500 dark:text-gray-400">Detalles</span><strong class="text-gray-950 dark:text-white">{{ $extraccion->detalles_guardados }}</strong></div><div><span class="block text-gray-500 dark:text-gray-400">Fallidos</span><strong class="text-danger-600 dark:text-danger-400">{{ $extraccion->errores }}</strong></div></div>
                        @if($extraccion->estado === 'pendiente')<p class="mt-3 text-xs text-gray-500 dark:text-gray-400">Encolada -- arranca en menos de un minuto.</p>@endif
                        @if(in_array($extraccion->estado,['fallido','completado_con_errores'],true) && $extraccion->mensaje_error)<p class="mt-3 text-sm font-medium text-danger-600 dark:text-danger-400">{{ $extraccion->mensaje_error }}</p>@endif
                    </x-filament::section>
                    @else
                    <x-filament::section><x-slot name="heading">Preparando extracción</x-slot><p class="text-sm text-gray-500 dark:text-gray-400">Encolando…</p></x-filament::section>
                    @endif
                </div>
            @endif
        </div>

        <div x-show="tab === 'cobertura'" x-cloak>@include('filament.pages.requerimientos-stock.partials.extraccion-cobertura')</div>
        <div x-show="tab === 'historial'" x-cloak><x-filament::section><x-slot name="heading">Historial de extracciones</x-slot>{{ $this->table }}</x-filament::section></div>

        @php($bloqueadoPorOtra = $this->hayExtraccionEnProgreso())
        <x-filament::modal id="filtros-extraccion-requerimientos" width="5xl" sticky-header sticky-footer>
            <x-slot name="heading">Filtros de extracción</x-slot>

            {{--
                Con el botón deshabilitado (abajo) y sin este wire:poll, un
                usuario que abre el modal justo cuando la sincronización
                automática de cada 30 min (o una extracción manual de otro
                usuario) está en curso presiona "Iniciar extracción" y NO
                pasa nada -- el botón deshabilitado nunca llega a disparar
                wire:submit, así que iniciarExtraccion() ni se ejecuta ni
                puede mostrar su propio mensaje de error. Sin este aviso ni
                el poll, la única señal era un botón inerte: exactamente el
                "se queda colgado" que se reportó. El poll hace que el botón
                se reactive solo apenas la otra extracción termine, sin que
                el usuario tenga que cerrar y reabrir el modal.
            --}}
            <div @if($bloqueadoPorOtra) wire:poll.5s @endif>
                @if($bloqueadoPorOtra)
                    <div class="mb-4 flex items-start gap-2 rounded-lg bg-warning-50 p-3 text-sm text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
                        <x-heroicon-o-clock class="mt-0.5 h-4 w-4 shrink-0" />
                        <span>Ya hay una extracción en curso (automática o de otro usuario). El botón "Iniciar extracción" se habilita solo apenas termine -- no hace falta cerrar este panel.</span>
                    </div>
                @endif

                <form id="filtros-extraccion-requerimientos-form" wire:submit.prevent="iniciarExtraccion" class="space-y-5">
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
            </div>

            <x-slot name="footerActions">
                <x-filament::button color="gray" wire:click="cerrarFiltrosExtraccion">Cancelar</x-filament::button>
                {{--
                    El bug real detrás de "no pasa nada al presionar": el prop
                    del componente x-filament::button que efectivamente
                    escribe el atributo HTML form="..." es `form-id`, no
                    `form` (ese otro solo alimenta el indicador de carga
                    interno). Con `form="..."` este botón se renderizaba sin
                    ningún atributo form en el HTML final y, al vivir en el
                    slot footerActions (fuera del <form> de arriba), quedaba
                    un <button type="submit"> sin ningún formulario al que
                    enviar -- el clic no disparaba nada, en cualquier momento,
                    con o sin otra extracción en curso.
                --}}
                <x-filament::button type="submit" form-id="filtros-extraccion-requerimientos-form" icon="heroicon-m-circle-stack" :disabled="$bloqueadoPorOtra">Iniciar extracción</x-filament::button>
            </x-slot>
        </x-filament::modal>
    </div>
</x-filament-panels::page>
