<div class="space-y-5">
    <div class="grid gap-x-8 gap-y-4 rounded-xl bg-gray-50 p-5 sm:grid-cols-2 xl:grid-cols-4 dark:bg-white/5">
        <div><p class="text-sm text-gray-500 dark:text-gray-400">Fecha</p><p class="mt-1 font-medium text-gray-950 dark:text-white">{{ optional($salida->fecha)->format('d/m/Y') }} {{ $salida->hora }}</p></div>
        <div><p class="text-sm text-gray-500 dark:text-gray-400">Local</p><p class="mt-1 font-medium text-gray-950 dark:text-white">{{ $salida->local_nombre ?: '—' }}</p></div>
        <div><p class="text-sm text-gray-500 dark:text-gray-400">Categoría</p><p class="mt-1 font-medium text-gray-950 dark:text-white">{{ $salida->categoria ?: '—' }}</p></div>
        <div><p class="text-sm text-gray-500 dark:text-gray-400">Responsable</p><p class="mt-1 font-medium text-gray-950 dark:text-white">{{ $salida->responsable ?: '—' }}</p></div>
        <div class="sm:col-span-2 xl:col-span-4"><p class="text-sm text-gray-500 dark:text-gray-400">Razón</p><p class="mt-1 font-medium text-gray-950 dark:text-white">{{ $salida->razon ?: '—' }}</p></div>
    </div>

    <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
        <table class="w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
            <thead class="bg-gray-50 dark:bg-white/5"><tr><th class="px-4 py-3 text-left font-semibold text-gray-950 dark:text-white">Ítem</th><th class="px-4 py-3 text-left font-semibold text-gray-950 dark:text-white">Almacén</th><th class="px-4 py-3 text-right font-semibold text-gray-950 dark:text-white">Cantidad</th><th class="px-4 py-3 text-left font-semibold text-gray-950 dark:text-white">Unidad</th><th class="px-4 py-3 text-right font-semibold text-gray-950 dark:text-white">Costo</th><th class="px-4 py-3 text-right font-semibold text-gray-950 dark:text-white">Total</th></tr></thead>
            <tbody class="divide-y divide-gray-200 bg-white dark:divide-white/10 dark:bg-gray-900">
                @forelse ($salida->detalles as $item)
                    <tr><td class="px-4 py-3 font-medium text-gray-950 dark:text-white">{{ $item->item ?: '—' }}</td><td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $item->almacen ?: '—' }}</td><td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-gray-950 dark:text-white">{{ number_format((float) $item->cantidad, 3) }}</td><td class="whitespace-nowrap px-4 py-3 text-gray-700 dark:text-gray-300">{{ $item->unidad ?: '—' }}</td><td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-gray-950 dark:text-white">{{ number_format((float) $item->costo, 2) }}</td><td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-gray-950 dark:text-white">{{ number_format((float) $item->total, 2) }}</td></tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">Sin ítems.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
