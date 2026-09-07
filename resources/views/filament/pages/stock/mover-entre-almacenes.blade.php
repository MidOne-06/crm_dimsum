<x-filament-panels::page>
    @if ($loadError)
        <x-filament::section><div class="text-danger-600 dark:text-danger-400">{{ $loadError }}</div></x-filament::section>
    @else
        <form wire:submit="previsualizar" class="space-y-6">
            {{ $this->form }}
            @if (! $this->canAddItems())
                <p class="text-sm text-gray-500 dark:text-gray-400">Completa local, almacenes y encargado antes de agregar ítems.</p>
            @endif
            @if ($preview !== [])
                <x-filament::section heading="Previsualización de Restaurant">
                    <p class="text-sm text-gray-600 dark:text-gray-300">Restaurant calculó el movimiento sin registrarlo todavía.</p>
                    @if ($stockRestricted)<p class="mt-2 text-sm font-medium text-danger-600">Restaurant restringió el guardado por stock negativo.</p>@endif
                </x-filament::section>
            @endif
            <div class="flex flex-wrap justify-end gap-3">
                <x-filament::button type="submit" icon="heroicon-m-eye" wire:loading.attr="disabled" wire:target="previsualizar">Previsualizar</x-filament::button>
                <x-filament::button type="button" color="success" icon="heroicon-m-check" wire:click="guardar" wire:confirm="Restaurant registrará este movimiento y afectará stock. ¿Deseas continuar?" wire:loading.attr="disabled" wire:target="guardar" :disabled="$stockRestricted">Guardar movimiento</x-filament::button>
            </div>
        </form>
    @endif
</x-filament-panels::page>
