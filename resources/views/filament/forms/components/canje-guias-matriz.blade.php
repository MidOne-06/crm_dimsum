<div wire:key="canje-guias-matriz">
    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
        <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
            <thead class="bg-gray-50 dark:bg-white/5">
                <tr>
                    <th scope="col" class="sticky left-0 z-10 min-w-72 border-r border-gray-200 bg-gray-50 px-3 py-2 text-left font-semibold text-gray-950 dark:border-white/10 dark:bg-gray-900 dark:text-white">
                        Ítem
                    </th>
                    @foreach ($groups as $groupIndex => $group)
                        <th scope="col" class="min-w-36 border-r border-gray-200 px-2 py-2 text-center font-semibold text-gray-950 dark:border-white/10 dark:text-white">
                            <span class="block">{{ $group['titulo'] ?? ('Movimiento '.($groupIndex + 1)) }}</span>
                        </th>
                    @endforeach
                    <th scope="col" class="min-w-24 px-3 py-2 text-right font-semibold text-gray-950 dark:text-white">Total</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white dark:divide-white/10 dark:bg-gray-950">
                @forelse ($rows as $row)
                    <tr>
                        <th scope="row" class="sticky left-0 z-10 border-r border-gray-200 bg-white px-3 py-2 text-left font-medium text-gray-950 dark:border-white/10 dark:bg-gray-950 dark:text-white">
                            <span class="block">{{ $row['descripcion'] }}</span>
                            <span class="block text-xs font-normal text-gray-500 dark:text-gray-400">
                                {{ collect([$row['codigo'], $row['presentacion'], $row['unidad']])->filter()->implode(' · ') }}
                            </span>
                        </th>
                        @foreach ($groups as $groupIndex => $group)
                            <td class="border-r border-gray-200 px-2 py-2 dark:border-white/10">
                                @if (array_key_exists($groupIndex, $row['cells']))
                                    @php($cantidadIndex = $row['cells'][$groupIndex])
                                    <input
                                        type="number"
                                        min="0"
                                        step="0.001"
                                        title="Al reducir un total, se distribuye entre las líneas de Restaurant en su orden original."
                                        wire:model.live.debounce.400ms="mountedActions.0.data.grupos.{{ $groupIndex }}.cantidades.{{ $cantidadIndex }}.cantidad"
                                        @disabled(! ($group['can_edit_quantity'] ?? false))
                                        class="fi-input block w-28 rounded-lg border-gray-300 bg-white px-2 py-1.5 text-right text-sm shadow-sm outline-none transition duration-75 placeholder:text-gray-400 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-500 dark:border-white/10 dark:bg-white/5 dark:text-white dark:disabled:bg-white/5"
                                    />
                                @else
                                    <span class="block text-center text-gray-400 dark:text-gray-600">—</span>
                                @endif
                            </td>
                        @endforeach
                        <td class="px-3 py-2 text-right font-semibold text-gray-950 dark:text-white">{{ number_format((float) $row['rowTotal'], 3, '.', '') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($groups) + 2 }}" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">Restaurant no devolvió ítems para esta selección.</td>
                    </tr>
                @endforelse
            </tbody>
            @if ($rows !== [])
                <tfoot class="bg-gray-50 font-semibold text-gray-950 dark:bg-white/5 dark:text-white">
                    <tr>
                        <th class="sticky left-0 z-10 border-r border-gray-200 bg-gray-50 px-3 py-2 text-left dark:border-white/10 dark:bg-gray-900">Total</th>
                        @foreach ($groups as $groupIndex => $group)
                            <td class="border-r border-gray-200 px-3 py-2 text-right dark:border-white/10">{{ number_format((float) ($totals[$groupIndex] ?? 0), 3, '.', '') }}</td>
                        @endforeach
                        <td class="px-3 py-2 text-right">{{ number_format((float) $grandTotal, 3, '.', '') }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>
