@if($error)
    <p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ $error }}</p>
@else
    <div class="space-y-5">
        <dl class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2 xl:grid-cols-4">
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Fecha</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $movimiento['fecha'] ?: '—' }}</dd></div>
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Estado</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $movimiento['estado'] ?: '—' }}</dd></div>
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Estado de recepción</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $movimiento['estadoRecepcion'] ?: '—' }}</dd></div>
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Valorización</dt><dd class="font-medium text-gray-950 dark:text-white">{{ number_format((float) ($movimiento['valorizado'] ?? 0), 2) }}</dd></div>
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Local de origen</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $movimiento['localOrigen'] ?: '—' }}</dd></div>
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Almacén de origen</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $movimiento['almacenOrigen'] ?: '—' }}</dd></div>
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Local de destino</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $movimiento['localDestino'] ?: '—' }}</dd></div>
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Almacén de destino</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $movimiento['almacenDestino'] ?: '—' }}</dd></div>
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Encargado</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $movimiento['encargado'] ?: '—' }}</dd></div>
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Receptor</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $movimiento['receptor'] ?: '—' }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-sm text-gray-500 dark:text-gray-400">Registrado por</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $movimiento['registradoPor'] ?: '—' }}</dd></div>
            @if($movimiento['observacion'])
                <div class="sm:col-span-2 xl:col-span-4"><dt class="text-sm text-gray-500 dark:text-gray-400">Observación</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $movimiento['observacion'] }}</dd></div>
            @endif
        </dl>

        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
            <table class="w-full text-left text-sm">
                <thead class="bg-gray-50 text-gray-700 dark:bg-white/5 dark:text-gray-200"><tr><th class="px-3 py-2">Código</th><th class="px-3 py-2">Ítem</th><th class="px-3 py-2">Presentación</th><th class="px-3 py-2 text-right">Cantidad</th><th class="px-3 py-2">Unidad</th><th class="px-3 py-2">Origen</th><th class="px-3 py-2">Destino</th><th class="px-3 py-2 text-right">Valorización</th></tr></thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @forelse($movimiento['items'] ?? [] as $item)
                        <tr><td class="px-3 py-2">{{ $item['codigo'] ?: '—' }}</td><td class="px-3 py-2">{{ $item['item'] ?: '—' }}</td><td class="px-3 py-2">{{ $item['presentacion'] ?: '—' }}</td><td class="px-3 py-2 text-right">{{ number_format((float) ($item['cantidad'] ?? 0), 3) }}</td><td class="px-3 py-2">{{ $item['unidad'] ?: '—' }}</td><td class="px-3 py-2">{{ $item['almacenOrigen'] ?: '—' }}</td><td class="px-3 py-2">{{ $item['almacenDestino'] ?: '—' }}</td><td class="px-3 py-2 text-right">{{ number_format((float) ($item['valorizado'] ?? 0), 2) }}</td></tr>
                    @empty
                        <tr><td colspan="8" class="px-3 py-6 text-center text-gray-500">Restaurant no devolvió ítems para este movimiento.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @foreach(['guias' => 'Guías internas vinculadas', 'requerimientos' => 'Requerimientos de stock vinculados', 'ordenes' => 'Órdenes de movimiento vinculadas', 'mermas' => 'Mermas vinculadas'] as $key => $label)
            <section><h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $label }}</h3><p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ collect($movimiento[$key] ?? [])->map(fn ($v) => trim('#'.($v['id'] ?? '').' '.($v['descripcion'] ?? '').' '.($v['estado'] ?? '')))->filter()->join(' · ') ?: 'No hay documentos vinculados.' }}</p></section>
        @endforeach
    </div>
@endif
