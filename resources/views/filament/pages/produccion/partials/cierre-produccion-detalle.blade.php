<div class="space-y-4">
    <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Estado</dt>
            <dd class="mt-1 font-medium text-gray-950 dark:text-white">{{ match($cierre->estado) { 'borrador' => 'Borrador', 'enviado' => 'Enviado', 'aprobado' => 'Aprobado', default => 'Nuevo' } }}</dd>
        </div>
        <div>
            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Bachs</dt>
            <dd class="mt-1 font-medium text-gray-950 dark:text-white">{{ $cierre->tandas->count() }}</dd>
        </div>
        <div>
            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Salidas</dt>
            <dd class="mt-1 font-medium text-gray-950 dark:text-white">{{ $cierre->salidas->count() }}</dd>
        </div>
        <div>
            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Físico</dt>
            <dd class="mt-1 font-medium text-gray-950 dark:text-white">{{ $cierre->detalles->whereNotNull('stock_final')->count() }}/{{ $cierre->detalles->count() }}</dd>
        </div>
    </dl>

    <x-filament::section compact heading="Productos">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b border-gray-200 text-left text-gray-500 dark:border-white/10 dark:text-gray-400">
                    <tr>
                        <th class="px-2 py-2 font-medium">Producto</th>
                        <th class="px-2 py-2 text-right font-medium">Inicial</th>
                        <th class="px-2 py-2 text-right font-medium">Producido</th>
                        <th class="px-2 py-2 text-right font-medium">Salidas</th>
                        <th class="px-2 py-2 text-right font-medium">Esperado</th>
                        <th class="px-2 py-2 text-right font-medium">Físico</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @forelse($cierre->detalles->sortBy('item_nombre') as $detalle)
                        <tr>
                            <td class="px-2 py-2 font-medium text-gray-950 dark:text-white">{{ $detalle->item_nombre }}</td>
                            <td class="px-2 py-2 text-right">{{ number_format((float) $detalle->stock_inicial, 2) }}</td>
                            <td class="px-2 py-2 text-right">{{ number_format((float) $detalle->producido_hoy, 2) }}</td>
                            <td class="px-2 py-2 text-right">{{ number_format((float) $detalle->salidas_hoy, 2) }}</td>
                            <td class="px-2 py-2 text-right">{{ number_format((float) $detalle->stock_esperado, 2) }}</td>
                            <td class="px-2 py-2 text-right">{{ $detalle->stock_final === null ? '—' : number_format((float) $detalle->stock_final, 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-2 py-4 text-center text-gray-500 dark:text-gray-400">Sin productos.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</div>
