@php($resumen = $this->resumenGeneral())
@php($activas = $this->extraccionesActivas())

<x-filament-panels::page>
    <div class="space-y-4" x-data="{ tab: 'nueva' }">
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
            <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color:#64748b"><span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Movimientos guardados</span><p class="text-xl font-semibold text-gray-950 dark:text-white">{{ number_format($resumen['movimientos']) }}</p></x-filament::section>
            <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color:#3e86d8"><span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Detalles guardados</span><p class="text-xl font-semibold text-gray-950 dark:text-white">{{ number_format($resumen['detalles']) }}</p></x-filament::section>
            <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color:#3e86d8"><span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Corridas totales</span><p class="text-xl font-semibold text-gray-950 dark:text-white">{{ number_format($resumen['corridas']) }}</p></x-filament::section>
            <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color:#dc2626"><span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Corridas fallidas</span><p class="text-xl font-semibold text-danger-600 dark:text-danger-400">{{ number_format($resumen['fallidas']) }}</p></x-filament::section>
            <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color:#16a34a"><span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Cobertura {{ $coverageYear }}</span><p class="text-xl font-semibold text-success-600 dark:text-success-400">{{ $resumen['coveragePercent'] }}%</p></x-filament::section>
        </div>

        <x-filament::tabs contained>
            <x-filament::tabs.item tag="button" icon="heroicon-o-circle-stack" alpine-active="tab === 'nueva'" x-on:click="tab = 'nueva'">Nueva extracción</x-filament::tabs.item>
            <x-filament::tabs.item tag="button" icon="heroicon-o-calendar-days" alpine-active="tab === 'cobertura'" x-on:click="tab = 'cobertura'">Cobertura</x-filament::tabs.item>
            <x-filament::tabs.item tag="button" icon="heroicon-o-clock" alpine-active="tab === 'historial'" x-on:click="tab = 'historial'">Historial <x-slot name="badge">{{ $resumen['corridas'] }}</x-slot></x-filament::tabs.item>
        </x-filament::tabs>

        <div x-show="tab === 'nueva'" class="space-y-4">
            <div class="flex justify-end">
                <x-filament::button icon="heroicon-m-circle-stack" wire:click="abrirFiltrosExtraccion">
                    {{ $activas->isEmpty() ? 'Nueva extracción' : 'Encolar otra extracción' }}
                </x-filament::button>
            </div>

            @if($activas->isNotEmpty())
                <div wire:poll.3s="refreshExtraccion" class="space-y-3">
                    @foreach($activas as $run)
                        @php($estancada = $this->estaEstancada($run))
                        <x-filament::section>
                            <x-slot name="heading">Extracción #{{ $run->id }} <span class="crm-status">{{ ucfirst(str_replace('_', ' ', $run->estado)) }}</span>
                                @if($estancada)<span class="ml-2 inline-flex items-center gap-1 rounded-full bg-danger-50 px-2 py-0.5 text-xs font-medium text-danger-600 dark:bg-danger-500/10 dark:text-danger-400"><x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5" /> Sin avance desde {{ $run->updated_at->diffForHumans() }}</span>@endif
                            </x-slot>
                            <x-slot name="afterHeader">
                                @if($run->estado === 'pendiente' && ! $estancada)
                                    <x-filament::button color="gray" icon="heroicon-m-trash" size="sm" wire:click="eliminarDeCola({{ $run->id }})" wire:confirm="¿Eliminar la extracción #{{ $run->id }} de la cola?">Quitar de la cola</x-filament::button>
                                @else
                                    <x-filament::button color="danger" icon="heroicon-m-stop-circle" size="sm" wire:click="cancelarExtraccion({{ $run->id }})" wire:confirm="¿Detener la extracción #{{ $run->id }}? El avance guardado se conserva.">{{ $estancada ? 'Cancelar (atascada)' : 'Detener' }}</x-filament::button>
                                @endif
                            </x-slot>
                            @if($estancada)<p class="mb-3 text-sm text-danger-600 dark:text-danger-400">Esta extracción no reporta avance hace más de 15 minutos. Puedes cancelarla con seguridad; el avance guardado se conserva.</p>@endif
                            @php($progress = $run->paginas_total > 0 ? min(100, round(($run->paginas_procesadas / $run->paginas_total) * 100)) : 0)
                            <div class="mb-3"><div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10"><div class="h-full rounded-full {{ $estancada ? 'bg-danger-500' : 'bg-primary-600' }} transition-all" style="width:{{ $progress }}%"></div></div><p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $progress }}% · {{ $run->paginas_procesadas }} de {{ $run->paginas_total ?: '—' }} páginas procesadas</p></div>
                            <div class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4"><div><span class="block text-gray-500 dark:text-gray-400">Movimientos</span><strong class="text-gray-950 dark:text-white">{{ $run->cabeceras_guardadas }}</strong></div><div><span class="block text-gray-500 dark:text-gray-400">Detalles</span><strong class="text-gray-950 dark:text-white">{{ $run->detalles_guardados }}</strong></div><div><span class="block text-gray-500 dark:text-gray-400">Eliminados</span><strong class="text-gray-950 dark:text-white">{{ $run->cabeceras_eliminadas }}</strong></div><div><span class="block text-gray-500 dark:text-gray-400">Fallidas</span><strong class="text-danger-600 dark:text-danger-400">{{ $run->errores }}</strong></div></div>
                        </x-filament::section>
                    @endforeach
                </div>
            @endif
        </div>

        <div x-show="tab === 'cobertura'" x-cloak>@include('filament.pages.stock.partials.extraccion-movimientos-cobertura')</div>
        <div x-show="tab === 'historial'" x-cloak><x-filament::section><x-slot name="heading">Historial de extracciones</x-slot>{{ $this->table }}</x-filament::section></div>

        <x-filament::modal id="filtros-extraccion-movimientos" width="5xl" sticky-header sticky-footer>
            <x-slot name="heading">Filtros de extracción de movimientos</x-slot>
            <form id="filtros-extraccion-movimientos-form" wire:submit.prevent="iniciarExtraccion" class="space-y-5">
                {{ $this->form }}
                @if($resultError)<p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ $resultError }}</p>@endif
            </form>
            <x-slot name="footerActions"><x-filament::button color="gray" wire:click="cerrarFiltrosExtraccion">Cancelar</x-filament::button><x-filament::button type="submit" form-id="filtros-extraccion-movimientos-form" icon="heroicon-m-circle-stack">Iniciar extracción</x-filament::button></x-slot>
        </x-filament::modal>
    </div>
</x-filament-panels::page>
