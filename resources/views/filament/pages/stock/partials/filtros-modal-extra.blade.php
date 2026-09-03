{{-- Campos que no son parte del Schema de Filament (rango de fechas con
     atajos, y el buscador de insumo/producto con sugerencias en vivo) --
     se embeben dentro del modal nativo de filtros vía un componente View,
     compartiendo el mismo Livewire de la página. --}}
<div class="space-y-4">
    <x-filament::fieldset label="Fecha" class="crm-filter-date">
        @include('filament.pages.stock.partials.date-range-picker', [
            'start' => $data['fechaInicio'] ?? now()->toDateString(),
            'end' => $data['fechaFin'] ?? now()->toDateString(),
            'preset' => $activeDatePreset,
            'syncMethod' => 'syncDateRange',
        ])
    </x-filament::fieldset>

    <x-filament::fieldset label="Contiene insumo/producto (máx. 5)" class="crm-filter-item">
        <x-filament::input.wrapper suffix-icon="heroicon-o-magnifying-glass">
            <x-filament::input
                type="search"
                autocomplete="off"
                wire:model.live.debounce.350ms="itemSearch"
                placeholder="Escribe para buscar un insumo o producto"
            />
        </x-filament::input.wrapper>

        @if (count($itemSuggestions))
            <div class="fi-dropdown-panel mt-2 flex flex-col gap-y-1 rounded-lg border border-gray-200 p-2 dark:border-white/10">
                @foreach ($itemSuggestions as $item)
                    <button
                        type="button"
                        wire:click="addItem('{{ $item['id'] }}', '{{ $item['type'] }}', '{{ addslashes($item['name']) }}')"
                        class="fi-dropdown-list-item flex items-center justify-between rounded-md px-2 py-1.5 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5"
                    >
                        <span>{{ $item['name'] }}</span>
                        @if ($item['code'])
                            <span class="text-xs text-gray-400">{{ $item['code'] }}</span>
                        @endif
                    </button>
                @endforeach
            </div>
        @endif

        @if (count($selectedItems))
            <div class="mt-2 flex flex-wrap gap-2">
                @foreach ($selectedItems as $index => $item)
                    <x-filament::badge color="gray">
                        {{ $item['name'] }}
                        <x-slot:deleteButton wire:click="removeItem({{ $index }})" :label="'Quitar '.$item['name']"></x-slot:deleteButton>
                    </x-filament::badge>
                @endforeach
            </div>
        @endif
    </x-filament::fieldset>
</div>
