<x-filament-panels::page>
    <form wire:submit="save" class="crm-settings-form crm-branding-form mx-auto max-w-6xl space-y-4">
        {{ $this->form }}

        <div class="crm-form-actions crm-branding-form__actions">
            <span class="crm-query-hint">Los cambios se aplican al guardar.</span>
            <x-filament::button type="submit" icon="heroicon-o-check" size="md">
                Guardar cambios
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
