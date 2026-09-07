@php($resumen = $this->resumenGeneral())
<x-filament-panels::page>
    <div wire:poll.10s="refreshExtraccion" class="space-y-6">
        <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
            <x-filament::section compact><span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Movimientos guardados</span><p class="text-2xl font-semibold">{{ number_format($resumen['movimientos']) }}</p></x-filament::section>
            <x-filament::section compact><span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Detalles guardados</span><p class="text-2xl font-semibold">{{ number_format($resumen['detalles']) }}</p></x-filament::section>
            <x-filament::section compact><span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Corridas totales</span><p class="text-2xl font-semibold">{{ number_format($resumen['corridas']) }}</p></x-filament::section>
            <x-filament::section compact><span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Corridas fallidas</span><p class="text-2xl font-semibold text-danger-600">{{ number_format($resumen['fallidas']) }}</p></x-filament::section>
        </div>

        <div class="flex justify-end">
            <x-filament::button icon="heroicon-m-circle-stack" wire:click="abrirFiltrosExtraccion">Nueva extracción</x-filament::button>
        </div>

        @php($activas = $this->extraccionesActivas())
        @if($activas->isNotEmpty())
            <div class="space-y-4">
                @foreach($activas as $run)
                    @php($estancada = $this->estaEstancada($run))
                    <x-filament::section>
                        <x-slot name="heading">Extracción #{{ $run->id }} <x-filament::badge :color="$estancada ? 'danger' : 'warning'">{{ ucfirst(str_replace('_', ' ', $run->estado)) }}</x-filament::badge></x-slot>
                        <x-slot name="headerEnd">
                            @if($run->estado === 'pendiente')
                                <x-filament::button size="sm" color="gray" wire:click="eliminarDeCola({{ $run->id }})">Quitar de cola</x-filament::button>
                            @else
                                <x-filament::button size="sm" color="danger" wire:click="cancelarExtraccion({{ $run->id }})">Cancelar</x-filament::button>
                            @endif
                        </x-slot>
                        @if($estancada)<p class="mb-3 text-sm text-danger-600 dark:text-danger-400">Esta extracción no reporta avance hace más de 15 minutos. Puedes cancelarla con seguridad; el avance guardado se conserva.</p>@endif
                        @php($progress = $run->paginas_total > 0 ? min(100, round(($run->paginas_procesadas / $run->paginas_total) * 100)) : 0)
                        <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10"><div class="h-full rounded-full {{ $estancada ? 'bg-danger-500' : 'bg-primary-600' }}" style="width:{{ $progress }}%"></div></div>
                        <p class="mt-1 text-xs text-gray-500">{{ $progress }}% · {{ $run->paginas_procesadas }} de {{ $run->paginas_total ?: '—' }} páginas procesadas</p>
                        <div class="mt-4 grid grid-cols-2 gap-4 text-sm sm:grid-cols-4"><div><span class="block text-gray-500">Movimientos</span><strong>{{ $run->cabeceras_guardadas }}</strong></div><div><span class="block text-gray-500">Detalles</span><strong>{{ $run->detalles_guardados }}</strong></div><div><span class="block text-gray-500">Eliminados</span><strong>{{ $run->cabeceras_eliminadas }}</strong></div><div><span class="block text-gray-500">Fallidas</span><strong class="text-danger-600">{{ $run->errores }}</strong></div></div>
                    </x-filament::section>
                @endforeach
            </div>
        @endif

        <x-filament::section>
            <x-slot name="heading">Historial de extracciones</x-slot>
            {{ $this->table }}
        </x-filament::section>

        <x-filament::modal id="filtros-extraccion-movimientos" width="5xl" sticky-header sticky-footer>
            <x-slot name="heading">Filtros de extracción de movimientos</x-slot>
            <form id="filtros-extraccion-movimientos-form" wire:submit.prevent="iniciarExtraccion" class="space-y-5">
                {{ $this->form }}
                @if($resultError)<p class="text-sm font-medium text-danger-600">{{ $resultError }}</p>@endif
            </form>
            <x-slot name="footerActions"><x-filament::button color="gray" wire:click="cerrarFiltrosExtraccion">Cancelar</x-filament::button><x-filament::button type="submit" form-id="filtros-extraccion-movimientos-form" icon="heroicon-m-circle-stack">Iniciar extracción</x-filament::button></x-slot>
        </x-filament::modal>
    </div>
</x-filament-panels::page>
