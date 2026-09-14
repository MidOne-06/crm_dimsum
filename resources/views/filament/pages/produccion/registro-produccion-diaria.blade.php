<x-filament-panels::page>
    @if (count($productosParaRegistro))
        <x-filament::section heading="Productos">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($productosParaRegistro as $producto)
                    @if ($this->puedeRegistrar() && ! $this->soloLectura())
                        <x-filament::button
                            type="button"
                            color="gray"
                            class="min-h-28 w-full !justify-start text-left"
                            wire:click="mountAction('registrarTandaProducto', { productoId: {{ $producto['id'] }} })"
                            wire:loading.attr="disabled"
                            wire:target="mountAction"
                        >
                            <span class="flex w-full flex-col items-start gap-1">
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $producto['codigo'] ?: 'Sin código' }}</span>
                                <span class="font-semibold text-gray-950 dark:text-white">{{ $producto['nombre'] }}</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ number_format($producto['disponible'], 2) }} {{ $producto['unidad'] }} · {{ $producto['tandas'] }} {{ $producto['tandas'] === 1 ? 'tanda' : 'tandas' }}</span>
                            </span>
                        </x-filament::button>
                    @else
                        <div class="min-h-28 rounded-xl border border-gray-200 p-4 dark:border-white/10">
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $producto['codigo'] ?: 'Sin código' }}</div>
                            <div class="mt-1 font-semibold text-gray-950 dark:text-white">{{ $producto['nombre'] }}</div>
                            <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ number_format($producto['disponible'], 2) }} {{ $producto['unidad'] }} · {{ $producto['tandas'] }} {{ $producto['tandas'] === 1 ? 'tanda' : 'tandas' }}</div>
                        </div>
                    @endif
                @endforeach
            </div>
        </x-filament::section>
    @endif

    @if ($mostrarCierre)
        <form class="space-y-4" wire:submit.prevent="guardarBorrador">
            {{ $this->form }}

            @if ($this->puedeRegistrar() && ! $this->soloLectura())
                <div class="sticky bottom-3 z-10 flex flex-wrap justify-end gap-3 rounded-xl border border-gray-200 bg-white/95 p-3 shadow-lg backdrop-blur dark:border-white/10 dark:bg-gray-900/95">
                    <x-filament::button type="submit" color="gray" wire:loading.attr="disabled" wire:target="guardarBorrador">Guardar borrador</x-filament::button>
                    <x-filament::button type="button" color="primary" wire:click="enviarCierre" wire:loading.attr="disabled" wire:target="enviarCierre">Enviar cierre</x-filament::button>
                </div>
            @endif
        </form>
    @elseif ($this->puedeRegistrar() && ! $this->soloLectura())
        <div class="flex justify-end">
            <x-filament::button type="button" color="gray" wire:click="abrirCierreFisico" wire:loading.attr="disabled" wire:target="abrirCierreFisico">Registrar cierre físico</x-filament::button>
        </div>
    @endif

    @if ($this->puedeAprobar() && $estado === 'enviado')
        <div class="flex justify-end">
            <x-filament::button type="button" color="success" wire:click="aprobar" wire:confirm="Se aprobará el cierre y quedará bloqueado. ¿Continuar?" wire:loading.attr="disabled" wire:target="aprobar">Aprobar cierre</x-filament::button>
        </div>
    @endif

    <x-filament::section heading="Producción acumulada de hoy">
        @if (count($resumenProduccion))
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($resumenProduccion as $resumen)
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                        <div class="text-sm font-medium text-gray-950 dark:text-white">{{ $resumen['nombre'] }}</div>
                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $resumen['codigo'] ?: 'Sin código' }} · {{ $resumen['tandas'] }} {{ $resumen['tandas'] === 1 ? 'tanda' : 'tandas' }}</div>
                        <div class="mt-3 text-2xl font-bold text-primary-600 dark:text-primary-400">{{ number_format($resumen['cantidad'], 2) }} <span class="text-sm font-medium">{{ $resumen['unidad'] }}</span></div>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">Sin registros.</p>
        @endif
    </x-filament::section>

    @if (count($tandasRecientes))
        <x-filament::section heading="Últimas tandas">
            <div class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($tandasRecientes as $tanda)
                    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-3">
                        <div>
                            <div class="font-medium text-gray-950 dark:text-white">{{ $tanda['producto'] }}</div>
                            @if ($tanda['nota'])
                                <div class="text-sm text-gray-500 dark:text-gray-400">{{ $tanda['nota'] }}</div>
                            @endif
                        </div>
                        <div class="text-right">
                            <div class="font-semibold text-gray-950 dark:text-white">{{ number_format($tanda['cantidad'], 2) }} {{ $tanda['unidad'] }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $tanda['hora'] }}@if ($tanda['usuario']) · {{ $tanda['usuario'] }}@endif</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    @if (empty($productosParaRegistro))
        <x-filament::section>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-gray-600 dark:text-gray-300">Sin productos activos.</p>
                @if (auth()->user()?->hasPermission('produccion-productos.manage'))
                    <x-filament::button tag="a" :href="\App\Filament\Resources\ProduccionProductoResource::getUrl()" color="gray">Gestionar productos</x-filament::button>
                @endif
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
