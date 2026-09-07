<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">Nuevo ajuste</x-slot>

            <form wire:submit="guardar" class="space-y-4">
                {{ $this->form }}

                <div class="flex justify-end">
                    <x-filament::button type="submit" icon="heroicon-o-check">
                        Guardar ajuste
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
