<x-filament-panels::page>
    <div class="space-y-4">
        @if ($gatewayUnavailable)
            <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger">
                <p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ $filtersError }}</p>
            </x-filament::section>
        @else
            <x-filament::section>
                <form wire:submit.prevent="search" class="space-y-3">
                    {{ $this->form }}

                    @if ($resultError)
                        <p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ $resultError }}</p>
                    @endif

                    <div class="flex justify-end">
                        <x-filament::button type="submit" icon="heroicon-o-magnifying-glass" :disabled="$isLoading">
                            {{ $isLoading ? 'Consultando…' : 'Consultar' }}
                        </x-filament::button>
                    </div>
                </form>

                @if ($hasSearched && count($items))
                    <div class="mt-3 border-t border-gray-200 pt-3 dark:border-white/10">
                        <div class="max-w-xl">
                            {{ $this->filtrosSchema }}
                        </div>

                        <div class="mt-3 flex flex-wrap items-end gap-3">
                            @if (($filtrosData['postFilterCategoria'] ?? '') !== '')
                                <x-filament::button type="button" size="sm" color="gray" wire:click="clearCategoriaFilter">
                                    Limpiar filtro
                                </x-filament::button>
                            @endif

                            @if (count($plantillas) && static::canUsarPlantilla())
                                <x-filament::button type="button" size="sm" color="gray" wire:click="usarPlantilla">
                                    Usar plantilla
                                </x-filament::button>
                                @if (count($plantillaAplicadaIndexes))
                                    <x-filament::button type="button" size="sm" :color="$soloDesdePlantilla ? 'info' : 'gray'" wire:click="toggleSoloDesdePlantilla">
                                        {{ $soloDesdePlantilla ? 'Ver todos los ítems ('.count($items).')' : 'Ver solo desde plantilla ('.count($plantillaAplicadaIndexes).')' }}
                                    </x-filament::button>
                                @endif
                            @endif
                        </div>

                        @if (static::canEditarValores())
                            <div class="mt-3 flex flex-wrap items-end gap-3">
                                <label class="text-sm font-medium text-gray-950 dark:text-white">
                                    Fecha del cuadre
                                    <x-filament::input.wrapper class="mt-1">
                                        <x-filament::input type="datetime-local" wire:model="cuadreFecha" />
                                    </x-filament::input.wrapper>
                                </label>
                                <label class="min-w-48 text-sm font-medium text-gray-950 dark:text-white">
                                    Motivo (opcional)
                                    <x-filament::input.wrapper class="mt-1">
                                        <x-filament::input type="text" wire:model="cuadreMotivo" placeholder="Ej. Conteo físico mensual" />
                                    </x-filament::input.wrapper>
                                </label>
                            </div>
                        @endif
                    </div>

                    @if ($plantillaError)
                        <p class="mt-2 text-sm font-medium text-danger-600 dark:text-danger-400">{{ $plantillaError }}</p>
                    @endif
                @endif
            </x-filament::section>

            @if ($hasSearched)
                @if (count($items))
                    @if (count($this->filteredItemIndexes()))
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                {{ count($this->filteredItemIndexes()) }} de {{ count($items) }} ítem(s)
                                @if (static::canGuardar())
                                    · {{ count($this->changedIndexes()) }} con cambios sin guardar
                                @endif
                                @if (static::canGuardarPlantilla())
                                    · {{ count($itemsSeleccionados) }} marcado(s) para plantilla
                                @endif
                            </p>

                            @if (static::canGuardar() || static::canGuardarPlantilla())
                                <div class="flex items-center gap-3">
                                    @if ($saveError)
                                        <p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ $saveError }}</p>
                                    @endif
                                    @if (static::canGuardarPlantilla())
                                        <x-filament::button size="sm" color="gray" icon="heroicon-o-bookmark" wire:click="abrirGuardarPlantilla" :disabled="empty($itemsSeleccionados)">
                                            Guardar como plantilla ({{ count($itemsSeleccionados) }})
                                        </x-filament::button>
                                    @endif
                                    @if (static::canGuardar())
                                        <x-filament::button size="sm" color="warning" icon="heroicon-o-arrow-up-tray" wire:click="openConfirmGuardar" :disabled="$isSaving">
                                            Guardar cuadre en Logística
                                        </x-filament::button>
                                    @endif
                                </div>
                            @endif
                        </div>

                        <div class="fi-ta-content crm-stock-final-table max-h-[65vh] overflow-auto rounded-xl border border-gray-200 dark:border-white/10">
                            <table class="fi-ta-table w-full text-start">
                                <thead class="sticky top-0 z-10 bg-white dark:bg-gray-900">
                                    <tr>
                                        @if (static::canGuardarPlantilla())
                                            <th class="fi-ta-header-cell w-10">
                                                <input type="checkbox" wire:click="toggleSeleccionarTodosFiltrados" @checked($this->todosFiltradosSeleccionados()) class="fi-checkbox-input rounded" title="Seleccionar todos los visibles" />
                                            </th>
                                        @endif
                                        <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Código</span></th>
                                        <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Ítem</span></th>
                                        <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Categoría</span></th>
                                        <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Stock sistema</span></th>
                                        @if (static::canEditarValores())
                                            <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Stock contado</span></th>
                                        @endif
                                        <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Costo</span></th>
                                        @if (static::canEditarValores())
                                            <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Costo nuevo</span></th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($this->paginatedItemIndexes() as $index)
                                        @php($item = $items[$index])
                                        @php($almacen = $item['almacenes'][0] ?? [])
                                        @php($changed = in_array($index, $this->changedIndexes(), true))
                                        @php($desdePlantilla = in_array($index, $plantillaAplicadaIndexes, true))
                                        @php($seleccionado = in_array($index, $itemsSeleccionados, true))
                                        <tr wire:key="stock-final-item-{{ $index }}" class="fi-ta-row @if($changed) bg-warning-50 dark:bg-warning-500/10 @elseif($desdePlantilla) bg-info-50 dark:bg-info-500/10 @endif">
                                            @if (static::canGuardarPlantilla())
                                                <td class="fi-ta-cell">
                                                    <input type="checkbox" wire:click="toggleItemSeleccionado({{ $index }})" @checked($seleccionado) class="fi-checkbox-input rounded" />
                                                </td>
                                            @endif
                                            <td class="fi-ta-cell"><div class="fi-ta-cell-content px-3 py-1.5">{{ $item['item_codigo'] ?? '—' }}</div></td>
                                            <td class="fi-ta-cell">
                                                <div class="fi-ta-cell-content px-3 py-1.5">
                                                    {{ $item['item_descripcion'] ?? '—' }}
                                                    @if ($desdePlantilla)
                                                        <span class="ms-1 rounded-full bg-info-100 px-2 py-0.5 text-xs font-medium text-info-700 dark:bg-info-500/20 dark:text-info-300">plantilla</span>
                                                    @endif
                                                </div>
                                            </td>
                                            <td class="fi-ta-cell"><div class="fi-ta-cell-content px-3 py-1.5">{{ $item['categoria_descripcion'] ?? '—' }}</div></td>
                                            <td class="fi-ta-cell"><div class="fi-ta-cell-content px-3 py-1.5 font-medium">{{ number_format((float) ($almacen['cantidad2'] ?? 0), 3) }}</div></td>
                                            @if (static::canEditarValores())
                                                <td class="fi-ta-cell">
                                                    <x-filament::input.wrapper class="max-w-24">
                                                        <x-filament::input type="number" step="any" wire:model="items.{{ $index }}.almacenes.0.inventario_cantidad" />
                                                    </x-filament::input.wrapper>
                                                </td>
                                            @endif
                                            <td class="fi-ta-cell"><div class="fi-ta-cell-content px-3 py-1.5">{{ number_format((float) ($almacen['costo'] ?? 0), 4) }}</div></td>
                                            @if (static::canEditarValores())
                                                <td class="fi-ta-cell">
                                                    <x-filament::input.wrapper class="max-w-24">
                                                        <x-filament::input type="number" step="any" wire:model="items.{{ $index }}.almacenes.0.costoNuevo" />
                                                    </x-filament::input.wrapper>
                                                </td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if ($this->itemsPageCount() > 1)
                            <div class="mt-3 flex items-center justify-between gap-3">
                                <x-filament::button type="button" size="sm" color="gray" wire:click="previousItemsPage" :disabled="$itemsPage === 1">
                                    Anterior
                                </x-filament::button>
                                <span class="text-sm text-gray-500 dark:text-gray-400">
                                    Página {{ $itemsPage }} de {{ $this->itemsPageCount() }}
                                </span>
                                <x-filament::button type="button" size="sm" color="gray" wire:click="nextItemsPage" :disabled="$itemsPage === $this->itemsPageCount()">
                                    Siguiente
                                </x-filament::button>
                            </div>
                        @endif
                    @else
                        <x-filament::empty-state icon="heroicon-o-funnel" heading="Ningún ítem coincide con el filtro de categoría." />
                    @endif
                @else
                    <x-filament::empty-state icon="heroicon-o-cube" heading="No hay ítems para los filtros seleccionados." />
                @endif
            @endif
        @endif
    </div>

    <x-filament::modal id="confirm-guardar-cuadre" width="5xl" sticky-header sticky-footer>
        <x-slot name="heading">Confirmar cuadre de stock</x-slot>

        <p class="text-sm text-gray-700 dark:text-gray-200">Guardar {{ count($this->changedIndexes()) }} ítem(s) en Logística.</p>

        <div class="mt-4 max-h-64 overflow-y-auto rounded-lg border border-gray-200 dark:border-white/10">
            <table class="fi-ta-table w-full text-start text-sm">
                <thead>
                    <tr>
                        <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Ítem</span></th>
                        <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Sistema</span></th>
                        <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Contado</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->changedIndexes() as $index)
                        @php($item = $items[$index])
                        @php($almacen = $item['almacenes'][0] ?? [])
                        <tr class="fi-ta-row">
                            <td class="fi-ta-cell"><div class="px-3 py-2 text-gray-950 dark:text-white">{{ $item['item_descripcion'] ?? '—' }}</div></td>
                            <td class="fi-ta-cell"><div class="px-3 py-2 text-gray-950 dark:text-white">{{ number_format((float) ($almacen['cantidad2'] ?? 0), 3) }}</div></td>
                            <td class="fi-ta-cell"><div class="px-3 py-2 font-medium text-warning-600 dark:text-warning-400">{{ number_format((float) ($almacen['inventario_cantidad'] ?? 0), 3) }}</div></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <x-slot name="footerActions">
            <x-filament::button color="gray" wire:click="cancelGuardar">Cancelar</x-filament::button>
            <x-filament::button color="warning" wire:click="guardar" :disabled="$isSaving">
                {{ $isSaving ? 'Guardando…' : 'Sí, guardar en Logística' }}
            </x-filament::button>
        </x-slot>
    </x-filament::modal>

    <x-filament::modal id="guardar-plantilla" width="lg" sticky-header sticky-footer>
        <x-slot name="heading">Guardar como plantilla</x-slot>

        <p class="text-sm text-gray-700 dark:text-gray-200">Guardar plantilla con {{ count($itemsSeleccionados) }} ítem(s).</p>

        <label class="mt-4 block text-sm font-medium text-gray-950 dark:text-white">
            Nombre de la plantilla
            <x-filament::input.wrapper class="mt-1">
                <x-filament::input type="text" wire:model="nombrePlantilla" placeholder="Ej. Conteo estándar Bellavista" />
            </x-filament::input.wrapper>
        </label>

        @if ($plantillaError)
            <p class="mt-2 text-sm font-medium text-danger-600 dark:text-danger-400">{{ $plantillaError }}</p>
        @endif

        <x-slot name="footerActions">
            <x-filament::button color="gray" wire:click="cancelarGuardarPlantilla">Cancelar</x-filament::button>
            <x-filament::button color="primary" wire:click="guardarComoPlantilla" :disabled="$isGuardandoPlantilla">
                {{ $isGuardandoPlantilla ? 'Guardando…' : 'Guardar plantilla' }}
            </x-filament::button>
        </x-slot>
    </x-filament::modal>
</x-filament-panels::page>
