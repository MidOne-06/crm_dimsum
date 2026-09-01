<x-filament::section
    collapsible
    :collapsed="$hasSearched && blank($resultError)"
    class="crm-query-section"
    x-on:sales-results-ready.window="isCollapsed = true"
>
    <x-slot name="heading">Filtros</x-slot>

    <form wire:submit="search" class="space-y-4">
        {{ $this->form }}

        <x-filament::fieldset label="Fecha" class="crm-filter-date">
            @include('filament.pages.ventas.partials.date-range-picker')
        </x-filament::fieldset>

        @if ($resultError && ! $hasSearched)
            <p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ $resultError }}</p>
        @endif

        <div class="crm-form-actions">
            <x-filament::button type="submit" icon="heroicon-m-magnifying-glass" wire:loading.attr="disabled" wire:target="search">
                Consultar
            </x-filament::button>
        </div>
    </form>
</x-filament::section>
