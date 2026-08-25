<div class="opm-stock-table">
    <div class="fi-ta-content overflow-x-auto">
        <table class="fi-ta-table w-full text-start">
            <thead>
                <tr>
                    <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Cód.</span></th>
                    <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Fecha</span></th>
                    <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Local</span></th>
                    <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Cliente</span></th>
                    <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Comprobante</span></th>
                    <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Subtotal</span></th>
                    <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Impuestos</span></th>
                    <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Total</span></th>
                    <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Pago</span></th>
                    <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Estado</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($salesRows as $row)
                    <tr class="fi-ta-row">
                        <td class="fi-ta-cell">
                            @if ($withDetail ?? false)
                                <button type="button" wire:click="openDetail('{{ $row['venta_id'] }}')" class="fi-link fi-link-size-sm px-3 py-3 text-primary-600 hover:underline dark:text-primary-400">
                                    {{ $row['venta_id'] }} · Ver
                                </button>
                            @else
                                <div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $row['venta_id'] ?? '—' }}</div>
                            @endif
                        </td>
                        <td class="fi-ta-cell"><div class="px-3 py-3 text-sm whitespace-nowrap text-gray-950 dark:text-white">{{ $row['venta_fecha'] ?? '—' }}</div></td>
                        <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $row['local_descripcion'] ?? '—' }}</div></td>
                        <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $row['cliente_descripciion'] ?? '—' }}</div></td>
                        <td class="fi-ta-cell"><div class="px-3 py-3 text-sm whitespace-nowrap text-gray-950 dark:text-white">{{ trim(($row['venta_tipodoc'] ?? '').' '.($row['venta_seriedoc'] ?? '').'-'.($row['venta_numdoc'] ?? ''), ' -') ?: '—' }}</div></td>
                        <td class="fi-ta-cell"><div class="opm-table-number px-3 py-3 text-sm text-gray-950 dark:text-white">{{ number_format((float) ($row['venta_subtotal'] ?? 0), 2) }}</div></td>
                        <td class="fi-ta-cell"><div class="opm-table-number px-3 py-3 text-sm text-gray-950 dark:text-white">{{ number_format((float) ($row['impuestos'] ?? 0), 2) }}</div></td>
                        <td class="fi-ta-cell"><div class="opm-table-number px-3 py-3 text-sm font-semibold text-gray-950 dark:text-white">{{ number_format((float) ($row['venta_total'] ?? 0), 2) }}</div></td>
                        <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $row['venta_formapago'] ?? '—' }}</div></td>
                        <td class="fi-ta-cell"><div class="px-3 py-3"><span class="opm-status">{{ $row['venta_estado'] ?? '—' }}</span></div></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @include('filament.pages.ventas.partials.pagination')
</div>
