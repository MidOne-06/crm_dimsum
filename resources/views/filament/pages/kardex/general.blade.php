<x-filament-panels::page>
    <div class="space-y-4">
        @if ($gatewayUnavailable)
            <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger">
                <p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ $filtersError }}</p>
            </x-filament::section>
        @else
            <div class="grid gap-4 lg:grid-cols-3">
                <x-filament::section heading="Filtros" class="lg:col-span-2">
                    <div class="space-y-4">
                        {{ $this->form }}

                        <x-filament::fieldset label="Fecha">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <x-filament::input.wrapper>
                                    <x-filament::input type="date" wire:model="fechaInicio" />
                                </x-filament::input.wrapper>
                                <x-filament::input.wrapper>
                                    <x-filament::input type="date" wire:model="fechaFin" />
                                </x-filament::input.wrapper>
                            </div>
                        </x-filament::fieldset>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="flex items-center gap-2 text-sm text-gray-950 dark:text-white">
                                <x-filament::input.checkbox wire:model="kardexValorizado" />
                                Kardex valorizado
                            </label>
                            <label class="flex items-center gap-2 text-sm text-gray-950 dark:text-white">
                                <x-filament::input.checkbox wire:model="verPrecioSinImpuestos" />
                                Ver precio sin impuestos
                            </label>
                        </div>

                        <x-filament::fieldset label="Incluir ítems">
                            <div class="flex flex-wrap gap-4">
                                <label class="flex items-center gap-2 text-sm text-gray-950 dark:text-white">
                                    <x-filament::input.checkbox wire:model="incluirDerivados" />
                                    Derivados
                                </label>
                                <label class="flex items-center gap-2 text-sm text-gray-950 dark:text-white">
                                    <x-filament::input.checkbox wire:model="incluirInsumos" />
                                    Insumos
                                </label>
                                <label class="flex items-center gap-2 text-sm text-gray-950 dark:text-white">
                                    <x-filament::input.checkbox wire:model="incluirProductos" />
                                    Productos
                                </label>
                            </div>
                        </x-filament::fieldset>
                    </div>
                </x-filament::section>

                <x-filament::section heading="Descargar">
                    <div class="space-y-4">
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model="version">
                                <option value="1">V1</option>
                                <option value="2">V2</option>
                                <option value="3">V3</option>
                            </x-filament::input.select>
                        </x-filament::input.wrapper>

                        @if ($downloadError)
                            <p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ $downloadError }}</p>
                        @endif

                        <div class="flex flex-col gap-2">
                            <x-filament::button
                                icon="heroicon-o-arrow-down-tray"
                                wire:click="descargar('excel')"
                                wire:loading.attr="disabled"
                                wire:target="descargar('excel'), descargar('csv')"
                            >
                                Descargar (.xlsx)
                            </x-filament::button>
                            <x-filament::button
                                color="gray"
                                icon="heroicon-o-arrow-down-tray"
                                wire:click="descargar('csv')"
                                wire:loading.attr="disabled"
                                wire:target="descargar('excel'), descargar('csv')"
                            >
                                Descargar (.csv)
                            </x-filament::button>
                        </div>
                    </div>
                </x-filament::section>
            </div>
        @endif
    </div>
</x-filament-panels::page>
