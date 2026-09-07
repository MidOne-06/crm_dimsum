<x-filament-panels::page>
    @if ($loadError)
        <x-filament::section><div class="text-danger-600 dark:text-danger-400">{{ $loadError }}</div></x-filament::section>
    @else
        <form class="space-y-4">
            {{ $this->form }}
            @if (! $this->canAddItems())
                <p class="text-sm text-gray-500 dark:text-gray-400">Completa local, almacenes y encargado antes de agregar ítems.</p>
            @endif
            <div class="flex flex-wrap justify-end gap-3">
                <x-filament::button type="button" color="success" icon="heroicon-m-check" wire:click="guardar" wire:confirm="Restaurant registrará este movimiento y afectará stock. ¿Deseas continuar?" wire:loading.attr="disabled" wire:target="guardar">Guardar movimiento</x-filament::button>
            </div>
        </form>
    @endif
</x-filament-panels::page>
