<x-filament-panels::page>
    <div class="space-y-4" wire:poll.60s="refrescarDatosRestaurant">
        @if ($gatewayUnavailable)
            <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger">
                <p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ $loadError }}</p>
            </x-filament::section>
        @else
            @if ($this->puedeUsarPlantillas())
                <x-filament::section compact icon="heroicon-o-document-duplicate" icon-color="primary">
                    <x-slot name="heading">Plantillas disponibles</x-slot>

                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <label class="block w-full sm:max-w-sm">
                            <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Local</span>
                            <select wire:model.live="plantillasLocalFilter" class="fi-select-input block w-full rounded-lg border-gray-300 bg-white text-sm text-gray-950 shadow-sm transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white">
                                @foreach ($this->plantillasLocalOptions() as $localId => $localName)
                                    <option value="{{ $localId }}">{{ $localName }}</option>
                                @endforeach
                            </select>
                        </label>
                        <p class="pb-2 text-sm text-gray-600 dark:text-gray-300">
                            {{ $this->esUsuarioTerminal()
                                ? 'Elige e importa una plantilla autorizada para crear tu requerimiento.'
                                : 'Selecciona una plantilla para cargar sus productos y cantidades.' }}
                        </p>
                    </div>

                    @if ($plantillasError)
                        <p class="mt-3 text-sm font-medium text-danger-600 dark:text-danger-400">{{ $plantillasError }}</p>
                    @elseif ($plantillasDisponibles === [])
                        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">No hay plantillas disponibles para este local.</p>
                    @else
                        <div class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                            @foreach ($plantillasDisponibles as $plantilla)
                                <div wire:key="plantilla-disponible-{{ $plantilla['id'] }}" class="flex items-center justify-between gap-3 rounded-xl border border-gray-200 p-3 dark:border-white/10">
                                    <div class="min-w-0">
                                        <p class="truncate font-semibold text-gray-950 dark:text-white">{{ $plantilla['nombre'] ?: 'Plantilla #'.$plantilla['id'] }}</p>
                                        <p class="mt-0.5 text-xs text-gray-600 dark:text-gray-300">
                                            {{ $plantilla['items_count'] }} ítems · {{ $plantilla['local_produccion'] ?? 'Restaurant' }}
                                        </p>
                                    </div>
                                    <x-filament::button
                                        wire:click="seleccionarPlantilla({{ (int) $plantilla['id'] }})"
                                        wire:loading.attr="disabled"
                                        wire:target="seleccionarPlantilla({{ (int) $plantilla['id'] }})"
                                        icon="heroicon-o-document-arrow-down"
                                        size="sm"
                                    >
                                        <span wire:loading.remove wire:target="seleccionarPlantilla({{ (int) $plantilla['id'] }})">Importar</span>
                                        <span wire:loading wire:target="seleccionarPlantilla({{ (int) $plantilla['id'] }})">Importando...</span>
                                    </x-filament::button>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-filament::section>
            @endif

            @if ($plantillaImportadaId)
                <x-filament::section compact icon="heroicon-o-document-check" icon-color="primary">
                    <x-slot name="heading">Plantilla importada #{{ $plantillaImportadaId }}</x-slot>
                    <p class="text-sm text-gray-600 dark:text-gray-300">
                        {{ $plantillaImportadaNombre ?: 'Puedes validar, guardar el requerimiento o actualizar esta plantilla si cuentas con el permiso.' }}
                        @if ($this->esUsuarioTerminal())
                            <span class="block mt-1">Revisa la plantilla y usa “Guardar requerimiento” para enviarla.</span>
                        @endif
                    </p>
                </x-filament::section>
            @endif

            @if ($loadError)
                <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger">
                    <p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ $loadError }}</p>
                </x-filament::section>
            @endif

            <form wire:submit="guardar">
                {{ $this->form }}
            </form>

            @if ($saveError)
                <p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ $saveError }}</p>
            @endif
        @endif
    </div>
</x-filament-panels::page>
