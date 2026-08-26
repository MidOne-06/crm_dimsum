<x-filament-panels::page>
    <div class="space-y-5">
        <x-filament::section heading="Plantillas de requerimiento">
            <div class="max-w-sm"><x-filament::input.wrapper label="Local solicitante"><x-filament::input.select wire:model.live="localId">@foreach ($availableLocals as $local)<option value="{{ $local['id'] }}">{{ $local['name'] }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></div>
        </x-filament::section>

        @if ($loadError)<x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger"><p class="text-sm font-medium text-danger-600">{{ $loadError }}</p></x-filament::section>@endif

        <div class="grid gap-5 xl:grid-cols-2">
            <x-filament::section heading="Selecciona una plantilla" :description="$total.' plantilla(s) disponibles'">
                @if (count($plantillas))
                    <div class="fi-ta-content overflow-x-auto">
                        <table class="fi-ta-table w-full min-w-[750px] text-start">
                            <thead><tr><th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Selecc.</span></th><th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Cód.</span></th><th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Nombre</span></th><th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Encargado</span></th><th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Receptor</span></th><th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Local producción</span></th><th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Ítems</span></th></tr></thead>
                            <tbody>
                                @foreach ($plantillas as $plantilla)
                                    @php($selected = ($plantillaSeleccionada['id'] ?? null) === $plantilla['id'])
                                    <tr @class(['fi-ta-row', 'bg-primary-50 dark:bg-primary-500/10' => $selected])>
                                        <td class="fi-ta-cell"><div class="px-3 py-3"><input class="fi-checkbox-input" type="radio" name="plantilla" wire:click="seleccionarPlantilla('{{ $plantilla['id'] }}')" @checked($selected) aria-label="Seleccionar plantilla {{ $plantilla['id'] }}" /></div></td>
                                        <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $plantilla['id'] }}</div></td>
                                        <td class="fi-ta-cell"><div class="px-3 py-3 text-sm font-medium text-gray-950 dark:text-white">{{ $plantilla['nombre'] }}</div></td>
                                        <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $plantilla['encargado'] ?: '—' }}</div></td>
                                        <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $plantilla['receptor'] ?: '—' }}</div></td>
                                        <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $plantilla['local_produccion'] }}</div></td>
                                        <td class="fi-ta-cell"><div class="opm-table-number px-3 py-3 text-sm font-medium text-gray-950 dark:text-white">{{ count($plantilla['recetas']) + count($plantilla['insumos']) + count($plantilla['productos']) }}</div></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <nav class="fi-pagination mt-3" aria-label="Paginación de plantillas">
                        @if ($page > 1)<x-filament::button type="button" size="sm" color="gray" class="fi-pagination-previous-btn" wire:click="goToPage({{ $page - 1 }})">Anterior</x-filament::button>@endif
                        <span class="fi-pagination-overview text-sm text-gray-500 dark:text-gray-400">Se muestran {{ (($page - 1) * $pageSize) + 1 }} a {{ min($page * $pageSize, $total) }} de {{ $total }} resultados</span>
                        @if ($page < $this->pages())<x-filament::button type="button" size="sm" color="gray" class="fi-pagination-next-btn" wire:click="goToPage({{ $page + 1 }})">Siguiente</x-filament::button>@endif
                        @include('filament.pages.stock.partials.pagination-pages', ['paginationCurrent' => $page, 'paginationPages' => $this->pages(), 'paginationAction' => 'goToPage'])
                    </nav>
                @else
                    <x-filament::empty-state icon="heroicon-o-document-duplicate" heading="No hay plantillas para este local." />
                @endif
            </x-filament::section>

            <x-filament::section heading="Vista previa">
                @if ($plantillaSeleccionada)
                    <div class="space-y-4">
                        <div class="grid gap-3 sm:grid-cols-2"><div><span class="text-sm text-gray-500 dark:text-gray-400">Plantilla</span><p class="font-medium text-gray-950 dark:text-white">#{{ $plantillaSeleccionada['id'] }} · {{ $plantillaSeleccionada['nombre'] }}</p></div><div><span class="text-sm text-gray-500 dark:text-gray-400">Destino</span><p class="font-medium text-gray-950 dark:text-white">{{ $plantillaSeleccionada['local_produccion'] }}</p></div></div>
                        <div class="grid grid-cols-3 gap-3 text-sm"><div>Recetas: <strong>{{ count($plantillaSeleccionada['recetas']) }}</strong></div><div>Insumos: <strong>{{ count($plantillaSeleccionada['insumos']) }}</strong></div><div>Productos: <strong>{{ count($plantillaSeleccionada['productos']) }}</strong></div></div>
                        <div class="fi-ta-content max-h-[27rem] overflow-auto"><table class="fi-ta-table w-full text-start"><thead class="sticky top-0 z-10"><tr><th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Tipo</span></th><th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Código</span></th><th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Ítem</span></th><th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Presentación</span></th><th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Cantidad</span></th></tr></thead><tbody>@foreach (['Receta' => $plantillaSeleccionada['recetas'], 'Insumo' => $plantillaSeleccionada['insumos'], 'Producto' => $plantillaSeleccionada['productos']] as $tipo => $items)@foreach ($items as $item)<tr class="fi-ta-row"><td class="fi-ta-cell"><div class="px-3 py-3 text-sm">{{ $tipo }}</div></td><td class="fi-ta-cell"><div class="px-3 py-3 text-sm">{{ $item['codigo'] }}</div></td><td class="fi-ta-cell"><div class="px-3 py-3 text-sm font-medium text-gray-950 dark:text-white">{{ $item['descripcion'] }}</div></td><td class="fi-ta-cell"><div class="px-3 py-3 text-sm">{{ $item['presentacion'] }}</div></td><td class="fi-ta-cell"><div class="opm-table-number px-3 py-3 text-sm">{{ $item['cantidad'] }} {{ $item['unidad'] }}</div></td></tr>@endforeach@endforeach</tbody></table></div>
                        <label class="flex items-center gap-2 text-sm"><input class="fi-checkbox-input" type="checkbox" wire:model="incluirCantidadesCero" /> Incluir artículos con cantidades cero</label>
                        <x-filament::button wire:click="importar">Importar plantilla seleccionada</x-filament::button><p class="text-xs text-gray-500">La importación solo precarga el nuevo requerimiento; no crea ni modifica información hasta que presiones Guardar.</p>
                    </div>
                @else
                    <x-filament::empty-state icon="heroicon-o-document-magnifying-glass" heading="Selecciona una plantilla para ver su detalle." />
                @endif
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
