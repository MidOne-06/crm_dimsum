@php($modulos = $this->resumen())

<x-filament-panels::page>
    <div class="space-y-4">
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2 xl:grid-cols-3">
            @foreach($modulos as $m)
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <span class="h-2.5 w-2.5 rounded-full {{ $m['activo'] ? 'bg-success-500' : 'bg-gray-400' }}"></span>
                            {{ $m['nombre'] }}
                        </div>
                    </x-slot>
                    <x-slot name="afterHeader">
                        <label class="inline-flex cursor-pointer items-center gap-2">
                            <span class="text-xs font-medium {{ $m['activo'] ? 'text-success-600 dark:text-success-400' : 'text-gray-400' }}">{{ $m['activo'] ? 'Activo' : 'Apagado' }}</span>
                            <span
                                wire:click="toggle('{{ $m['modulo'] }}')"
                                wire:confirm="{{ $m['activo'] ? "¿Desactivar {$m['nombre']}? Las extracciones manuales siguen funcionando igual." : "¿Reactivar {$m['nombre']}?" }}"
                                class="relative inline-flex h-5 w-9 items-center rounded-full transition {{ $m['activo'] ? 'bg-success-500' : 'bg-gray-300 dark:bg-gray-600' }}"
                                <span class="inline-block h-4 w-4 transform rounded-full bg-white transition {{ $m['activo'] ? 'translate-x-4' : 'translate-x-0.5' }}"></span>
                            </span>
                        </label>
                    </x-slot>

                    <div class="space-y-2 text-sm">
                        <div class="flex items-center justify-between text-gray-500 dark:text-gray-400">
                            <span>Cadencia automática</span>
                            <span class="font-medium text-gray-800 dark:text-gray-100">{{ $m['cadencia'] }}</span>
                        </div>

                        @if($m['activas'] > 0)
                            <div class="rounded-md bg-info-50 px-2.5 py-1.5 text-xs font-medium text-info-700 dark:bg-info-500/10 dark:text-info-400">
                                {{ $m['activas'] }} corrida{{ $m['activas'] === 1 ? '' : 's' }} en curso ahora mismo
                            </div>
                        @endif

                        <div class="rounded-md border border-gray-100 p-2.5 dark:border-white/5">
                            @if($m['ultima'])
                                <div class="mb-1 flex items-center justify-between">
                                    <span class="crm-status text-xs">{{ ucfirst(str_replace('_',' ',$m['ultima']['estado'])) }}</span>
                                    <span class="text-xs text-gray-400">{{ $m['ultima']['fecha'] }}</span>
                                </div>
                                <p class="text-xs text-gray-600 dark:text-gray-300">{{ $m['ultima']['detalle'] }}</p>
                            @else
                                <p class="text-xs text-gray-400">Sin corridas.</p>
                            @endif
                        </div>

                        @if(! $m['activo'] && $m['desactivado_en'])
                            <p class="text-xs text-gray-400">Apagado por {{ $m['desactivado_por'] ?? 'alguien' }} · {{ $m['desactivado_en']->format('d/m/Y H:i') }}</p>
                        @endif

                        <button type="button" wire:click="verHistorial('{{ $m['modulo'] }}')" class="text-xs font-medium text-primary-600 hover:underline dark:text-primary-400">
                            Historial →
                        </button>
                    </div>
                </x-filament::section>
            @endforeach
        </div>

        <x-filament::modal id="historial-sincronizacion" width="4xl" sticky-header sticky-footer>
            <x-slot name="heading">Historial -- {{ $this->nombreModuloDetalle() }}</x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-start text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-1.5 pe-2 text-start">Cód.</th>
                            <th class="py-1.5 pe-2 text-start">Fecha</th>
                            <th class="py-1.5 pe-2 text-start">Estado</th>
                            <th class="py-1.5 text-start">Detalle</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($this->historialModulo() as $fila)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-1.5 pe-2 text-gray-500">#{{ $fila['id'] }}</td>
                                <td class="py-1.5 pe-2 whitespace-nowrap text-gray-600 dark:text-gray-300">{{ $fila['fecha'] }}</td>
                                <td class="py-1.5 pe-2"><span class="crm-status text-xs">{{ ucfirst(str_replace('_',' ',$fila['estado'])) }}</span></td>
                                <td class="py-1.5 text-gray-600 dark:text-gray-300">
                                    {{ $fila['detalle'] }}
                                    @if($fila['mensaje_error'])
                                        <p class="mt-0.5 text-xs text-danger-600 dark:text-danger-400">{{ \Illuminate\Support\Str::limit($fila['mensaje_error'], 140) }}</p>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-4 text-center text-gray-400">Sin corridas registradas.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-slot name="footerActions">
                <x-filament::button color="gray" wire:click="cerrarHistorial">Cerrar</x-filament::button>
            </x-slot>
        </x-filament::modal>
    </div>
</x-filament-panels::page>
