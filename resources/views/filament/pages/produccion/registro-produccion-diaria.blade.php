<x-filament-panels::page>
    @if ($diaAnteriorSinCerrar || $diaAnteriorSinAprobar)
        <div class="rounded-xl border border-danger-300 bg-danger-50 p-4 text-sm text-danger-700 dark:border-danger-500/30 dark:bg-danger-500/10 dark:text-danger-300">
            @if ($diaAnteriorSinCerrar)
                <strong>Atención:</strong> el {{ \Illuminate\Support\Carbon::parse($diaAnteriorFecha)->format('d/m/Y') }} no se registró ningún cierre de producción.
            @else
                <strong>Atención:</strong> el cierre del {{ \Illuminate\Support\Carbon::parse($diaAnteriorFecha)->format('d/m/Y') }} quedó sin aprobar.
            @endif
        </div>
    @endif

    @if (count($productosParaRegistro))
        @foreach ($productosPorCategoria as $categoria => $productos)
            <x-filament::section :heading="$categoria" compact>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    @foreach ($productos as $producto)
                        @if (($this->puedeRegistrar() || $this->puedeRegistrarTanda()) && ! $this->soloLectura())
                            <div class="min-h-28 w-full rounded-xl border border-gray-200 p-3 dark:border-white/10">
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $producto['codigo'] ?: 'Sin código' }}</div>
                                <div class="font-semibold text-gray-950 dark:text-white">{{ $producto['nombre'] }}</div>
                                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    Disponible: {{ number_format($producto['disponible'], 2) }} {{ $producto['unidad'] }}
                                    @if ($producto['salidas_hoy'] > 0)
                                        · salió {{ number_format($producto['salidas_hoy'], 2) }}
                                    @endif
                                </div>
                                <div class="mt-2 flex gap-2">
                                    <x-filament::button
                                        type="button"
                                        color="primary"
                                        size="sm"
                                        class="flex-1 !justify-center"
                                        wire:click="mountAction('registrarTandaProducto', { productoId: {{ $producto['id'] }} })"
                                        wire:loading.attr="disabled"
                                        wire:target="mountAction"
                                    >Tanda</x-filament::button>
                                    @if ($this->puedeRegistrar())
                                        <x-filament::button
                                            type="button"
                                            color="gray"
                                            size="sm"
                                            class="flex-1 !justify-center"
                                            wire:click="mountAction('registrarSalidaProducto', { productoId: {{ $producto['id'] }} })"
                                            wire:loading.attr="disabled"
                                            wire:target="mountAction"
                                        >Salida</x-filament::button>
                                    @endif
                                </div>
                            </div>
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
        @endforeach
    @endif

    @if ($this->puedeRegistrar() && ! $this->soloLectura())
        <div class="flex justify-end">
            <x-filament::button type="button" color="gray" wire:click="mountAction('registrarCierreFisico')" wire:loading.attr="disabled" wire:target="mountAction">Registrar cierre físico</x-filament::button>
        </div>
    @endif

    @if ($this->puedeAprobar() && $estado === 'enviado')
        <div class="flex justify-end">
            <x-filament::button type="button" color="success" wire:click="aprobar" wire:confirm="Se aprobará el cierre y quedará bloqueado. ¿Continuar?" wire:loading.attr="disabled" wire:target="aprobar">Aprobar cierre</x-filament::button>
        </div>
    @endif

    @if ($this->puedeReabrir())
        <div class="flex justify-end">
            <x-filament::button type="button" color="warning" wire:click="reabrir" wire:confirm="Vuelve a borrador: se podrán corregir tandas, salidas y el stock final antes de aprobarlo de nuevo. ¿Continuar?" wire:loading.attr="disabled" wire:target="reabrir">Reabrir cierre</x-filament::button>
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
                        <div class="flex items-center gap-3">
                            <div class="text-right">
                                <div class="font-semibold text-gray-950 dark:text-white">{{ number_format($tanda['cantidad'], 2) }} {{ $tanda['unidad'] }}</div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $tanda['hora'] }}@if ($tanda['usuario']) · {{ $tanda['usuario'] }}@endif</div>
                            </div>
                            @if ($estado === 'borrador' && $this->puedeRegistrarTanda())
                                <div class="flex gap-1">
                                    <x-filament::icon-button icon="heroicon-o-pencil-square" label="Editar" wire:click="mountAction('editarTanda', { tandaId: {{ $tanda['id'] }} })" wire:loading.attr="disabled" wire:target="mountAction" />
                                    <x-filament::icon-button icon="heroicon-o-trash" color="danger" label="Eliminar" wire:click="mountAction('eliminarTanda', { tandaId: {{ $tanda['id'] }} })" wire:loading.attr="disabled" wire:target="mountAction" />
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    @if (count($salidasRecientes))
        <x-filament::section heading="Últimas salidas">
            <div class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($salidasRecientes as $salida)
                    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-3">
                        <div>
                            <div class="font-medium text-gray-950 dark:text-white">{{ $salida['producto'] }}</div>
                            <div class="text-sm text-gray-500 dark:text-gray-400">
                                {{ match ($salida['destino']) { 'despacho' => 'Área de despacho', 'merma' => 'Merma / descarte', 'ajuste' => 'Ajuste de conteo', default => 'Otro' } }}
                                @if ($salida['nota']) · {{ $salida['nota'] }} @endif
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="text-right">
                                <div class="font-semibold text-gray-950 dark:text-white">−{{ number_format($salida['cantidad'], 2) }} {{ $salida['unidad'] }}</div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $salida['hora'] }}@if ($salida['usuario']) · {{ $salida['usuario'] }}@endif</div>
                            </div>
                            @if ($estado === 'borrador' && $this->puedeRegistrar())
                                <div class="flex gap-1">
                                    <x-filament::icon-button icon="heroicon-o-pencil-square" label="Editar" wire:click="mountAction('editarSalida', { salidaId: {{ $salida['id'] }} })" wire:loading.attr="disabled" wire:target="mountAction" />
                                    <x-filament::icon-button icon="heroicon-o-trash" color="danger" label="Eliminar" wire:click="mountAction('eliminarSalida', { salidaId: {{ $salida['id'] }} })" wire:loading.attr="disabled" wire:target="mountAction" />
                                </div>
                            @endif
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
