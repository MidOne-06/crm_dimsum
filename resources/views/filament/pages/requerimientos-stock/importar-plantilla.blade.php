<x-filament-panels::page>
    <div class="space-y-4">
        @if ($loadError)
            <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger">
                <p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ $loadError }}</p>
            </x-filament::section>
        @endif

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            @forelse ($localOptions as $localId => $localNombre)
                <x-filament::button
                    wire:key="plantilla-local-{{ $localId }}"
                    color="gray"
                    icon="heroicon-o-building-storefront"
                    class="min-h-16 w-full justify-start"
                    wire:click="abrirPlantillasLocal({{ (int) $localId }})"
                    wire:loading.attr="disabled"
                    wire:target="abrirPlantillasLocal({{ (int) $localId }})"
                >
                    <span class="truncate">{{ $localNombre }}</span>
                </x-filament::button>
            @empty
                <div class="text-sm text-gray-600 dark:text-gray-300">
                    Sin sucursales disponibles.
                </div>
            @endforelse
        </div>
    </div>

    <x-filament::modal id="plantillas-del-local" width="4xl" sticky-header sticky-footer>
        <x-slot name="heading">{{ $plantillasLocalNombre }}</x-slot>

        <div class="grid gap-3 sm:grid-cols-2">
            @forelse ($plantillasDelLocal as $plantilla)
                <x-filament::section compact wire:key="plantilla-local-item-{{ $plantilla['id'] }}">
                    <div class="space-y-3">
                        <p class="truncate text-sm font-semibold text-gray-950 dark:text-white">
                            {{ $plantilla['nombre'] ?: 'Plantilla #'.$plantilla['id'] }}
                        </p>

                        <div class="grid grid-cols-2 gap-3 text-sm">
                            <div>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">Producción</span>
                                <span class="font-medium text-gray-950 dark:text-white">{{ $plantilla['local_produccion'] ?: '—' }}</span>
                            </div>
                            <div>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">Ítems</span>
                                <span class="font-medium text-gray-950 dark:text-white">{{ $plantilla['items_count'] }}</span>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <x-filament::button size="sm" color="gray" icon="heroicon-o-eye" wire:click="verPlantillaDelLocal({{ (int) $plantilla['id'] }})">
                                Ver
                            </x-filament::button>

                            @if (auth()->user()?->hasPermission('requerimientos-stock.plantillas.importar'))
                                <x-filament::button size="sm" icon="heroicon-o-document-arrow-down" wire:click="abrirImportacionPlantilla({{ (int) $plantilla['id'] }})">
                                    Importar
                                </x-filament::button>
                            @endif
                        </div>
                    </div>
                </x-filament::section>
            @empty
                <div class="col-span-full text-sm text-gray-600 dark:text-gray-300">
                    Sin plantillas configuradas para esta sucursal.
                </div>
            @endforelse
        </div>

        <x-slot name="footer">
            <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'plantillas-del-local' })">Cerrar</x-filament::button>
        </x-slot>
    </x-filament::modal>

    <x-filament::modal id="vista-previa-plantilla" width="7xl" sticky-header sticky-footer>
        <x-slot name="heading">
            {{ $plantillaVistaPrevia ? ($plantillaVistaPrevia['nombre'] ?: 'Plantilla #'.$plantillaVistaPrevia['id']) : 'Plantilla' }}
        </x-slot>

        @if ($plantillaVistaPrevia)
            @include('filament.pages.requerimientos-stock.plantilla-modal', ['plantilla' => $plantillaVistaPrevia])
        @endif

        <x-slot name="footer">
            <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'vista-previa-plantilla' })">Cerrar</x-filament::button>
        </x-slot>
    </x-filament::modal>

    <x-filament::modal id="confirmar-importacion-plantilla" width="lg" sticky-header sticky-footer>
        <x-slot name="heading">
            {{ $plantillaPendienteImportacion ? 'Importar · '.($plantillaPendienteImportacion['nombre'] ?: 'Plantilla #'.$plantillaPendienteImportacion['id']) : 'Importar plantilla' }}
        </x-slot>

        <label class="flex items-center gap-3 text-sm font-medium text-gray-950 dark:text-white">
            <x-filament::input.checkbox wire:model="incluirCantidadesCero" />
            Incluir cantidades cero
        </label>

        <x-slot name="footer">
            <div class="flex items-center gap-3">
                <x-filament::button icon="heroicon-o-document-arrow-down" wire:click="confirmarImportacionPlantilla" wire:loading.attr="disabled" wire:target="confirmarImportacionPlantilla">
                    Importar
                </x-filament::button>
                <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'confirmar-importacion-plantilla' })">Cancelar</x-filament::button>
            </div>
        </x-slot>
    </x-filament::modal>
</x-filament-panels::page>
