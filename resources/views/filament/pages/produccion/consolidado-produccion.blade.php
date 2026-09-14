<x-filament-panels::page>
    <div class="space-y-4">
        <form wire:submit="aplicarMes" class="flex flex-wrap items-end gap-3">
            <div class="w-full sm:w-72">{{ $this->form }}</div>
            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="aplicarMes">Actualizar</x-filament::button>
        </form>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
