<x-filament-panels::page>
    <form wire:submit="save" class="opm-settings-form mx-auto max-w-4xl space-y-6">
        {{ $this->form }}

        <div class="opm-form-actions">
            <x-filament::button type="submit" icon="heroicon-o-check" size="lg">
                Guardar cambios
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
