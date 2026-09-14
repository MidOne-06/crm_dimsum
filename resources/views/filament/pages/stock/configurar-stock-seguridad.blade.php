<x-filament-panels::page>
    <form wire:submit="save" class="crm-settings-form mx-auto max-w-3xl space-y-4">
        {{ $this->form }}

        <div class="crm-form-actions">
            <x-filament::button type="submit" icon="heroicon-o-check" size="md">
                Guardar cambios
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
