@php($cabecera = $detalle['cabecera'] ?? [])
@php($columnasItems = [
    'codigo' => ['label' => 'Cód.'],
    'item' => ['label' => 'Ítem'],
    'categoria' => ['label' => 'Categoría'],
    'presentacion' => ['label' => 'Presentación'],
    'cantidad_solicitada' => ['label' => 'Solicitada', 'numeric' => true],
    'cantidad_despachada' => ['label' => 'Despachada', 'numeric' => true],
    'cantidad_preparada' => ['label' => 'Preparada', 'numeric' => true],
    'unidad' => ['label' => 'Unidad'],
    'almacen' => ['label' => 'Almacén'],
    'observacion' => ['label' => 'Observación'],
])
@php($columnasHistorial = [
    'fecha' => ['label' => 'Fecha'],
    'tipo' => ['label' => 'Evento'],
    'detalles' => ['label' => 'Ítems', 'numeric' => true],
])
@php($columnasRestaurant = [
    'fecha' => ['label' => 'Fecha'],
    'evento' => ['label' => 'Evento'],
    'usuario' => ['label' => 'Usuario'],
])

<div class="space-y-5">
    <x-filament::section>
        <dl class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                'Registro' => $cabecera['fecha_registro'] ?? '',
                'Abastecimiento' => $cabecera['fecha_abastecimiento'] ?? '',
                'Solicitado por' => $cabecera['solicitado_por'] ?? '',
                'Producción' => $cabecera['local_produccion'] ?? '',
                'Encargado' => $cabecera['encargado'] ?? '',
                'Receptor' => $cabecera['receptor'] ?? '',
                'Estado' => $cabecera['estado'] ?? '',
                'Observación' => $cabecera['observacion'] ?? '',
            ] as $label => $value)
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                    <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ filled($value) ? $value : '—' }}</dd>
                </div>
            @endforeach
        </dl>
    </x-filament::section>

    <x-filament::section heading="Ítems">
        <livewire:requerimientos-stock.tabla :rows="$detalle['detalles'] ?? []" :columns="$columnasItems" wire:key="requerimiento-items-{{ $cabecera['codigo'] ?? 'sin-codigo' }}" />
    </x-filament::section>

    <x-filament::section heading="Historial">
        <livewire:requerimientos-stock.tabla :rows="$historial ?? []" :columns="$columnasHistorial" wire:key="requerimiento-historial-{{ $cabecera['codigo'] ?? 'sin-codigo' }}" />
    </x-filament::section>

    @if (filled($historialRestaurant ?? []))
        <x-filament::section heading="Historial de Restaurant">
            <livewire:requerimientos-stock.tabla :rows="$historialRestaurant" :columns="$columnasRestaurant" wire:key="requerimiento-restaurant-{{ $cabecera['codigo'] ?? 'sin-codigo' }}" />
        </x-filament::section>
    @endif
</div>
