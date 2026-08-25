@if ($gatewayUnavailable)
    <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger">
        <p class="fi-in-text text-sm font-medium text-danger-600 dark:text-danger-400">{{ $filtersError }}</p>
    </x-filament::section>
@else
    <x-filament::section
        class="opm-query-section"
        heading="Filtros"
        collapsible
        :collapsed="$hasSearched && blank($resultError)"
        x-on:stock-results-ready.window="isCollapsed = true"
    >

        <form wire:submit.prevent="search" class="opm-filter-form space-y-4">
            {{ $this->form }}

            <x-filament::fieldset label="Fecha" class="opm-filter-date">
                @include('filament.pages.stock.partials.date-range-picker')
            </x-filament::fieldset>

            <x-filament::fieldset label="Contiene insumo/producto (máx. 5)" class="opm-filter-item">
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

            <div class="opm-query-actions">
                <x-filament::button type="submit" icon="heroicon-o-magnifying-glass" :disabled="$isLoading">
                    {{ $isLoading ? 'Buscando…' : 'Buscar' }}
                </x-filament::button>
            </div>

            @if ($resultError)
                <p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ $resultError }}</p>
            @endif
        </form>
    </x-filament::section>
@endif
