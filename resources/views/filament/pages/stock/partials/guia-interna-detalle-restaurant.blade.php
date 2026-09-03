@if($error)
    <p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ $error }}</p>
@else
    <div class="space-y-5">
        <dl class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2 xl:grid-cols-4">
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Serie</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $guia['serie'] ?: '—' }}</dd></div>
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Número</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $guia['correlativo'] ?: '—' }}</dd></div>
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Emisión</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $guia['fechaEmision'] ?: '—' }}</dd></div>
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Traslado</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $guia['fechaTraslado'] ?: '—' }}</dd></div>
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Origen</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $guia['localOrigen'] ?: '—' }}</dd></div>
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Destino</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $guia['localDestino'] ?: '—' }}</dd></div>
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Almacén</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $guia['almacen'] ?: '—' }}</dd></div>
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Estado</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $guia['estado'] ?: '—' }}</dd></div>
            <div class="sm:col-span-2 xl:col-span-4"><dt class="text-sm text-gray-500 dark:text-gray-400">Dirección</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $guia['direccionDestino'] ?: '—' }}</dd></div>
        </dl>

        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
            <table class="w-full text-left text-sm">
                <thead class="bg-gray-50 text-gray-700 dark:bg-white/5 dark:text-gray-200"><tr><th class="px-3 py-2">Código</th><th class="px-3 py-2">Ítem</th><th class="px-3 py-2">Presentación</th><th class="px-3 py-2 text-right">Cantidad</th><th class="px-3 py-2">Unidad</th><th class="px-3 py-2 text-right">Total</th></tr></thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @forelse($guia['items'] ?? [] as $item)
                        <tr><td class="px-3 py-2">{{ $item['codigo'] ?: '—' }}</td><td class="px-3 py-2">{{ $item['item'] ?: '—' }}</td><td class="px-3 py-2">{{ $item['presentacion'] ?: '—' }}</td><td class="px-3 py-2 text-right">{{ number_format((float) ($item['cantidad'] ?? 0), 3) }}</td><td class="px-3 py-2">{{ $item['unidad'] ?: '—' }}</td><td class="px-3 py-2 text-right">{{ number_format((float) ($item['total'] ?? 0), 2) }}</td></tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-6 text-center text-gray-500">Restaurant no devolvió ítems para esta guía.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif
