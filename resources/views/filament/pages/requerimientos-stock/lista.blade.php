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
                @if($itemResults)<div class="absolute z-10 mt-1 w-full rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-800">@foreach($itemResults as $index => $item)<button type="button" wire:click="agregarItemFiltro({{ $index }})" class="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-700">{{ $item['item_descripcion'] }} <span class="text-gray-500">({{ $item['item_codigo'] }})</span></button>@endforeach</div>@endif
                @if($selectedItems)<div class="mt-2 flex flex-wrap gap-2">@foreach($selectedItems as $index => $item)<span class="inline-flex items-center gap-1 rounded-full bg-primary-100 px-3 py-1 text-xs text-primary-700 dark:bg-primary-500/20 dark:text-primary-300">{{ $item['nombre'] }} <button type="button" wire:click="quitarItemFiltro({{ $index }})" aria-label="Quitar">×</button></span>@endforeach</div>@endif
            </div>
            <div class="mt-4"><x-filament::button wire:click="buscar">Aplicar filtros</x-filament::button></div>
        </x-filament::section>

        @if($loadError)<x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger"><p class="text-sm font-medium text-danger-600">{{ $loadError }}</p></x-filament::section>@endif

        <x-filament::section heading="Lista de requerimientos" :description="$total.' requerimiento(s) encontrados'">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1350px] text-sm">
                    <thead class="border-b border-gray-200 text-left text-gray-500 dark:border-gray-700"><tr><th class="p-2">Cód.</th><th class="p-2">Fecha registro</th><th class="p-2">Fecha abastecimiento</th><th class="p-2">Solicitado por</th><th class="p-2">Local prod.</th><th class="p-2">Encargado</th><th class="p-2">Receptor</th><th class="p-2">Movimiento</th><th class="p-2">Proceso prod.</th><th class="p-2">Otros doc. vinc.</th><th class="p-2">Estado despacho</th><th class="p-2">Estado</th><th class="p-2">Fecha aprobación</th></tr></thead>
                    <tbody>@forelse($rows as $row)<tr class="border-b border-gray-100 align-top dark:border-gray-800"><td class="p-2 font-medium">{{ $row['codigo'] }}</td><td class="p-2">{{ $row['fecha_registro'] }}</td><td class="p-2">{{ $row['fecha_abastecimiento'] }}</td><td class="p-2">{{ $row['solicitado_por'] }}</td><td class="p-2">{{ $row['local_produccion'] }}</td><td class="p-2">{{ $row['encargado'] }}</td><td class="p-2">{{ $row['receptor'] }}</td><td class="p-2">{{ $row['movimiento'] }}</td><td class="p-2">{{ $row['proceso_produccion'] }}</td><td class="p-2">{{ $row['otros_documentos'] }}</td><td class="p-2">{{ $row['estado_despacho'] }}</td><td class="p-2">{{ $row['estado'] }}</td><td class="p-2">{{ $row['fecha_aprobacion'] }}</td></tr>@empty<tr><td colspan="13" class="p-6 text-center text-gray-500">No hay requerimientos para los filtros indicados.</td></tr>@endforelse</tbody>
                </table>
            </div>
            <div class="mt-4 flex items-center gap-3"><x-filament::button size="sm" color="gray" wire:click="goToPage({{ $page - 1 }})" :disabled="$page <= 1">Anterior</x-filament::button><span class="text-sm text-gray-500">Página {{ $page }} de {{ $this->pages() }}</span><x-filament::button size="sm" color="gray" wire:click="goToPage({{ $page + 1 }})" :disabled="$page >= $this->pages()">Siguiente</x-filament::button><x-filament::input.select class="ml-auto" wire:model.live="pageSize"><option value="10">10 por página</option><option value="25">25 por página</option><option value="50">50 por página</option><option value="100">100 por página</option></x-filament::input.select></div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
