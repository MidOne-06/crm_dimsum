<x-filament::fieldset label="Filtros de resultados" class="opm-report-filters mb-4">
    <div class="grid gap-4 md:grid-cols-4">
        <label class="text-sm font-medium text-gray-950 dark:text-white">
            Local
            <x-filament::input.wrapper class="mt-1">
                <x-filament::input.select wire:change="applyReportFilter('local', $event.target.value)">
                    <option value="" @selected($reportFilterLocal === '')>Todos los locales</option>
                    @foreach ($this->reportLocalOptions() as $value)
                        <option value="{{ $value }}" @selected($reportFilterLocal === $value)>{{ $value }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>
        @if ($showAlmacenFilter ?? true)
            <label class="text-sm font-medium text-gray-950 dark:text-white">
                Almacén
                <x-filament::input.wrapper class="mt-1">
                    <x-filament::input.select wire:change="applyReportFilter('almacen', $event.target.value)">
                        <option value="" @selected($reportFilterAlmacen === '')>Todos los almacenes</option>
                        @foreach ($this->reportAlmacenOptions() as $value)
                            <option value="{{ $value }}" @selected($reportFilterAlmacen === $value)>{{ $value }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
        @endif
        <label class="text-sm font-medium text-gray-950 dark:text-white">
            Ítem
            <x-filament::input.wrapper class="mt-1">
                <x-filament::input.select wire:change="applyReportFilter('item', $event.target.value)">
                    <option value="" @selected($reportFilterItem === '')>Todos los ítems</option>
                    @foreach ($this->reportItemOptions() as $value)
                        <option value="{{ $value }}" @selected($reportFilterItem === $value)>{{ $value }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>
        <label class="text-sm font-medium text-gray-950 dark:text-white">
            Tipo
            <x-filament::input.wrapper class="mt-1">
                <x-filament::input.select wire:change="applyReportFilter('tipo', $event.target.value)">
                    <option value="" @selected($reportFilterTipo === '')>Todos los tipos</option>
                    @foreach ($this->reportTipoOptions() as $value)
                        <option value="{{ $value }}" @selected($reportFilterTipo === $value)>{{ $value }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>
    </div>
    <div class="mt-3">
        <x-filament::button type="button" size="xs" color="gray" wire:click="clearReportFilters">
            Limpiar filtros
        </x-filament::button>
    </div>
</x-filament::fieldset>
