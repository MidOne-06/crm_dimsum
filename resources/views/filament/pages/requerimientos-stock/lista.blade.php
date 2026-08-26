<x-filament-panels::page>
    <div class="space-y-5">
        <x-filament::section heading="Filtros de búsqueda">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <x-filament::input.wrapper label="Filtrar fecha por"><x-filament::input.select wire:model="fechaTipo"><option value="0">Fecha de registro</option><option value="1">Fecha de abastecimiento</option></x-filament::input.select></x-filament::input.wrapper>
                <x-filament::input.wrapper label="Desde"><x-filament::input type="date" wire:model="fechaInicio" /></x-filament::input.wrapper>
                <x-filament::input.wrapper label="Hasta"><x-filament::input type="date" wire:model="fechaFin" /></x-filament::input.wrapper>
                <x-filament::input.wrapper label="Estado">
                    <x-filament::input.select wire:model="estado"><option value="-1">Todos</option><option value="0">Anulado</option><option value="1">Pendiente</option><option value="2">Aprobado</option><option value="3">Rechazado</option><option value="4">Despachado</option><option value="5">Recibido</option></x-filament::input.select>
                </x-filament::input.wrapper>
                <x-filament::input.wrapper label="Código"><x-filament::input type="text" wire:model="codigo" placeholder="Ej. 5926" /></x-filament::input.wrapper>
                <x-filament::input.wrapper label="Encargado"><x-filament::input type="text" wire:model="encargado" placeholder="Nombre del encargado" /></x-filament::input.wrapper>
                <x-filament::input.wrapper label="Solicitado por"><x-filament::input.select multiple size="5" wire:model="selectedLocals">@foreach($availableLocals as $local)<option value="{{ $local['id'] }}">{{ $local['name'] }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>
                <x-filament::input.wrapper label="Local de producción"><x-filament::input.select multiple size="5" wire:model="selectedProductionLocals">@foreach($availableLocals as $local)<option value="{{ $local['id'] }}">{{ $local['name'] }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>
            </div>
            <div class="relative mt-4">
                <x-filament::input.wrapper label="Contiene insumo o producto (máximo 5)"><x-filament::input type="text" wire:model.live.debounce.400ms="itemSearch" placeholder="Escribe al menos 3 letras..." /></x-filament::input.wrapper>
                @if($itemResults)
                    <ul class="absolute z-10 mt-1 w-full max-h-64 overflow-auto rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-800">
                        @foreach($itemResults as $index => $item)
                            <li>
                                <button type="button" wire:click="agregarItemFiltro({{ $index }})" class="w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-700">
                                    <span class="font-medium text-gray-950 dark:text-white">{{ $item['item_descripcion'] }}</span>
                                    <span class="text-gray-500 dark:text-gray-400">({{ $item['item_codigo'] }})</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if($selectedItems)
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach($selectedItems as $index => $item)
                            <x-filament::badge color="primary">
                                {{ $item['nombre'] }}

                                <x-slot
                                    name="deleteButton"
                                    label="Quitar {{ $item['nombre'] }}"
                                    wire:click="quitarItemFiltro({{ $index }})"
                                ></x-slot>
                            </x-filament::badge>
                        @endforeach
                    </div>
                @endif
            </div>
            <div class="mt-4"><x-filament::button wire:click="buscar">Aplicar filtros</x-filament::button></div>
        </x-filament::section>

        @if($loadError)
            <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger">
                <p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ $loadError }}</p>
            </x-filament::section>
        @endif

        <x-filament::section heading="Lista de requerimientos" :description="$total.' requerimiento(s) encontrados'">
            @if (empty($rows))
                <x-filament::empty-state icon="heroicon-o-clipboard-document-list" heading="No hay requerimientos para los filtros indicados." />
            @else
                <div class="fi-ta-content overflow-x-auto">
                    <table class="fi-ta-table w-full min-w-[1350px] text-start">
                        <thead>
                            <tr>
                                <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Cód.</span></th>
                                <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Fecha registro</span></th>
                                <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Fecha abastecimiento</span></th>
                                <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Solicitado por</span></th>
                                <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Local prod.</span></th>
                                <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Encargado</span></th>
                                <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Receptor</span></th>
                                <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Movimiento</span></th>
                                <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Proceso prod.</span></th>
                                <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Otros doc. vinc.</span></th>
                                <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Estado despacho</span></th>
                                <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Estado</span></th>
                                <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Fecha aprobación</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $row)
                                <tr class="fi-ta-row">
                                    <td class="fi-ta-cell"><div class="px-3 py-3 text-sm font-medium text-gray-950 dark:text-white">{{ $row['codigo'] }}</div></td>
                                    <td class="fi-ta-cell"><div class="px-3 py-3 text-sm whitespace-nowrap text-gray-950 dark:text-white">{{ $row['fecha_registro'] }}</div></td>
                                    <td class="fi-ta-cell"><div class="px-3 py-3 text-sm whitespace-nowrap text-gray-950 dark:text-white">{{ $row['fecha_abastecimiento'] }}</div></td>
                                    <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $row['solicitado_por'] }}</div></td>
                                    <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $row['local_produccion'] }}</div></td>
                                    <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $row['encargado'] }}</div></td>
                                    <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $row['receptor'] }}</div></td>
                                    <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $row['movimiento'] }}</div></td>
                                    <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $row['proceso_produccion'] }}</div></td>
                                    <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $row['otros_documentos'] }}</div></td>
                                    <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $row['estado_despacho'] }}</div></td>
                                    <td class="fi-ta-cell">
                                        <div class="px-3 py-3">
                                            @if($row['estado'])<x-filament::badge color="gray">{{ $row['estado'] }}</x-filament::badge>@endif
                                        </div>
                                    </td>
                                    <td class="fi-ta-cell"><div class="px-3 py-3 text-sm whitespace-nowrap text-gray-950 dark:text-white">{{ $row['fecha_aprobacion'] }}</div></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            <div class="mt-4 flex items-center gap-3">
                <x-filament::button size="sm" color="gray" wire:click="goToPage({{ $page - 1 }})" :disabled="$page <= 1">Anterior</x-filament::button>
                <span class="text-sm text-gray-500 dark:text-gray-400">Página {{ $page }} de {{ $this->pages() }}</span>
                <x-filament::button size="sm" color="gray" wire:click="goToPage({{ $page + 1 }})" :disabled="$page >= $this->pages()">Siguiente</x-filament::button>
                <x-filament::input.select class="ml-auto" wire:model.live="pageSize">
                    <option value="10">10 por página</option>
                    <option value="25">25 por página</option>
                    <option value="50">50 por página</option>
                    <option value="100">100 por página</option>
                </x-filament::input.select>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
