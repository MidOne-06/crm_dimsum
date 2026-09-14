<x-filament-panels::page>
    <form class="space-y-4" wire:submit.prevent="registrarTanda">
        {{ $this->form }}

        @if ($this->puedeRegistrar() && ! $this->soloLectura())
            <div class="sticky bottom-3 z-10 flex flex-wrap justify-end gap-3 rounded-xl border border-gray-200 bg-white/95 p-3 shadow-lg backdrop-blur dark:border-white/10 dark:bg-gray-900/95">
                <x-filament::button type="submit" size="lg" icon="heroicon-m-plus" wire:loading.attr="disabled" wire:target="registrarTanda">
                    Registrar tanda
                </x-filament::button>
                @if (! $mostrarCierre)
                    <x-filament::button type="button" size="lg" color="gray" wire:click="abrirCierreFisico" wire:loading.attr="disabled" wire:target="abrirCierreFisico">
                        Registrar cierre físico
                    </x-filament::button>
                @else
                    <x-filament::button type="button" color="gray" wire:click="guardarBorrador" wire:loading.attr="disabled" wire:target="guardarBorrador">Guardar borrador</x-filament::button>
                    <x-filament::button type="button" color="primary" wire:click="enviarCierre" wire:loading.attr="disabled" wire:target="enviarCierre">Enviar cierre</x-filament::button>
                @endif
            </div>
        @endif
        @if ($this->puedeAprobar() && $estado === 'enviado')
            <div class="flex justify-end">
                <x-filament::button type="button" color="success" wire:click="aprobar" wire:confirm="Se aprobará el cierre y quedará bloqueado. Kardex no será modificado. ¿Continuar?" wire:loading.attr="disabled" wire:target="aprobar">Aprobar cierre</x-filament::button>
            </div>
        @endif
    </form>

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
            <p class="text-sm text-gray-500 dark:text-gray-400">Aún no se registraron tandas hoy.</p>
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
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $tanda['hora'] }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    @if (empty($data['items'] ?? []))
        <x-filament::section>
            <p class="text-sm text-gray-600 dark:text-gray-300">No hay productos con cantidad sugerida en la DT de hoy.</p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
