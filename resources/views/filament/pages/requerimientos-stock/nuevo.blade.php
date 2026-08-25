<x-filament-panels::page>
    <div class="space-y-4">
        @if ($gatewayUnavailable)
            <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger">
                <p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ $loadError }}</p>
            </x-filament::section>
        @else
            @if ($loadError)
                <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger">
                    <p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ $loadError }}</p>
                </x-filament::section>
            @endif

            <x-filament::section heading="Datos del requerimiento">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <x-filament::input.wrapper label="Local origen">
                        <x-filament::input.select wire:model.live="localOrigenId">
                            @foreach ($availableLocals as $local)
                                <option value="{{ $local['id'] }}">{{ $local['name'] }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>

                    <x-filament::input.wrapper label="Almacén">
                        <x-filament::input.select wire:model="almacenOrigenId">
                            @foreach ($almacenOptions as $almacen)
                                <option value="{{ $almacen['id'] }}">{{ $almacen['nombre'] }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>

                    <x-filament::input.wrapper label="Local destino">
                        <x-filament::input.select wire:model="localDestinoId">
                            <option value="">Selecciona...</option>
                            @foreach ($availableLocals as $local)
                                <option value="{{ $local['id'] }}">{{ $local['name'] }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>

                    <x-filament::input.wrapper label="Encargado">
                        <x-filament::input type="text" wire:model="encargado" />
                    </x-filament::input.wrapper>

                    <x-filament::input.wrapper label="Dirigido a (receptor)">
                        <x-filament::input type="text" wire:model="receptor" />
                    </x-filament::input.wrapper>

                    <x-filament::input.wrapper label="Día de abastecimiento">
                        <x-filament::input type="datetime-local" min="{{ $fechaMinima }}" wire:model="fecha" />
                    </x-filament::input.wrapper>
                </div>

                <div class="mt-4">
                    <x-filament::input.wrapper label="Observación">
                        <x-filament::input type="text" wire:model="observacion" />
                    </x-filament::input.wrapper>
                </div>
            </x-filament::section>

            <footer class="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                <h3 class="font-semibold text-gray-950 dark:text-white">Guía para completar el requerimiento</h3>
                <dl class="mt-3 grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
                    <div><dt class="font-medium text-gray-950 dark:text-white">Local origen y almacén</dt><dd>Indican de dónde saldrá el stock solicitado.</dd></div>
                    <div><dt class="font-medium text-gray-950 dark:text-white">Local destino</dt><dd>Es el local que recibirá el abastecimiento.</dd></div>
                    <div><dt class="font-medium text-gray-950 dark:text-white">Encargado y receptor</dt><dd>Identifican a quien gestiona y a quien recibe el pedido.</dd></div>
                    <div><dt class="font-medium text-gray-950 dark:text-white">Día de abastecimiento</dt><dd>Elige manualmente en el calendario la fecha y hora programadas; debe ser desde el día siguiente.</dd></div>
                    <div><dt class="font-medium text-gray-950 dark:text-white">Ítems y cantidad</dt><dd>Busca cada insumo o producto y registra la cantidad requerida.</dd></div>
                    <div><dt class="font-medium text-gray-950 dark:text-white">Observación</dt><dd>Agrega instrucciones útiles para el despacho o la recepción.</dd></div>
                </dl>
            </footer>

            <x-filament::section heading="Ítems">
                <div class="space-y-4">
                    <div class="relative">
                        <x-filament::input.wrapper label="Buscar ítem">
                            <x-filament::input
                                type="text"
                                wire:model.live.debounce.400ms="searchQuery"
                                placeholder="Escribe al menos 3 letras..."
                            />
                        </x-filament::input.wrapper>

                        @if (! empty($searchResults))
                            <ul class="absolute z-10 mt-1 w-full max-h-64 overflow-auto rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-800">
                                @foreach ($searchResults as $index => $result)
                                    <li>
                                        <button
                                            type="button"
                                            wire:click="agregarItem({{ $index }})"
                                            class="w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-700"
                                        >
                                            <span class="font-medium text-gray-950 dark:text-white">{{ $result['item_descripcion'] }}</span>
                                            <span class="text-gray-500 dark:text-gray-400">({{ $result['item_codigo'] }})</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    @if (! empty($items))
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 text-left text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                    <th class="py-2">Ítem</th>
                                    <th class="py-2">Código</th>
                                    <th class="py-2 w-32">Cantidad</th>
                                    <th class="py-2 w-12"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($items as $index => $entry)
                                    <tr class="border-b border-gray-100 dark:border-gray-800">
                                        <td class="py-2 text-gray-950 dark:text-white">{{ $entry['item']['item_descripcion'] }}</td>
                                        <td class="py-2 text-gray-500 dark:text-gray-400">{{ $entry['item']['item_codigo'] }}</td>
                                        <td class="py-2">
                                            <x-filament::input type="number" step="0.01" min="0.01" wire:model="items.{{ $index }}.cantidad" />
                                        </td>
                                        <td class="py-2">
                                            <x-filament::icon-button icon="heroicon-o-trash" color="danger" wire:click="quitarItem({{ $index }})" />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <p class="text-sm text-gray-500 dark:text-gray-400">Aún no agregas ítems.</p>
                    @endif
                </div>
            </x-filament::section>

            @if ($saveError)
                <p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ $saveError }}</p>
            @endif

            <div class="flex flex-col gap-2 sm:flex-row">
                <x-filament::button
                    wire:click="guardar(false)"
                    wire:loading.attr="disabled"
                    wire:target="guardar(false), guardar(true)"
                >
                    Guardar requerimiento
                </x-filament::button>
                <x-filament::button
                    color="gray"
                    wire:click="guardar(true)"
                    wire:loading.attr="disabled"
                    wire:target="guardar(false), guardar(true)"
                >
                    Guardar como solicitud de compra
                </x-filament::button>
            </div>
        @endif
    </div>
</x-filament-panels::page>
