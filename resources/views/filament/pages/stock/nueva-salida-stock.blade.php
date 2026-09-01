<x-filament-panels::page>
    <div class="space-y-4" wire:poll.60s="refrescarDatosRestaurant">
        @if ($saveSuccess)
            <x-filament::section icon="heroicon-o-check-circle" icon-color="success">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Salida registrada correctamente</h2>
                        <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">
                            Se registraron {{ $saveSuccess['items'] }} {{ $saveSuccess['items'] === 1 ? 'producto' : 'productos' }} para {{ $saveSuccess['local'] }}.
                        </p>
                        <p class="mt-1 text-sm font-medium text-gray-700 dark:text-gray-300">La pantalla ya está lista para registrar otra salida.</p>
                    </div>

                    <x-filament::button type="button" color="success" wire:click="continuarRegistro">
                        Registrar otra salida
                    </x-filament::button>
                </div>
            </x-filament::section>
        @endif

        @if ($loadError)
            <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger">
                <p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ $loadError }}</p>
            </x-filament::section>
        @endif

        <form wire:submit="guardar">
            {{ $this->form }}
        </form>
    </div>
</x-filament-panels::page>
