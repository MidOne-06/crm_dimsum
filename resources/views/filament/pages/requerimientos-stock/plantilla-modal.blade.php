@php($filasPlantilla = collect(['Receta' => $plantilla['recetas'] ?? [], 'Insumo' => $plantilla['insumos'] ?? [], 'Producto' => $plantilla['productos'] ?? []])
    ->flatMap(fn (array $items, string $tipo) => collect($items)->map(fn (array $item) => [
        'tipo' => $tipo,
        'codigo' => $item['codigo'] ?? '',
        'item' => $item['descripcion'] ?? '',
        'presentacion' => $item['presentacion'] ?? '',
        'cantidad' => $item['cantidad'] ?? 0,
        'unidad' => $item['unidad'] ?? '',
    ]))->values()->all())
@php($columnasPlantilla = [
    'tipo' => ['label' => 'Tipo'],
    'codigo' => ['label' => 'Código'],
    'item' => ['label' => 'Ítem'],
    'presentacion' => ['label' => 'Presentación'],
    'cantidad' => ['label' => 'Cantidad', 'numeric' => true],
    'unidad' => ['label' => 'Unidad'],
])

<div class="space-y-4">
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div><span class="block text-sm font-medium leading-6 text-gray-950 dark:text-white">Nombre</span><p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $plantilla['nombre'] ?? '—' }}</p></div>
        <div><span class="block text-sm font-medium leading-6 text-gray-950 dark:text-white">Solicitado por</span><p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $plantilla['local_origen'] ?? '—' }}</p></div>
        <div><span class="block text-sm font-medium leading-6 text-gray-950 dark:text-white">Producción</span><p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $plantilla['local_produccion'] ?? '—' }}</p></div>
        <div><span class="block text-sm font-medium leading-6 text-gray-950 dark:text-white">Encargado</span><p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $plantilla['encargado'] ?: '—' }}</p></div>
    </div>

    <x-filament::section heading="Ítems" compact>
        <livewire:requerimientos-stock.tabla :rows="$filasPlantilla" :columns="$columnasPlantilla" wire:key="plantilla-items-{{ $plantilla['id'] ?? 'sin-id' }}" />
    </x-filament::section>
</div>
