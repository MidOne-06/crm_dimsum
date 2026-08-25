@php($rows = $this->rows())

@if ($rows->isEmpty())
    <x-filament::empty-state icon="heroicon-o-archive-box" heading="No hay ventas guardadas para los filtros seleccionados." />
@else
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
                    @foreach ($rows as $row)
                        <tr class="fi-ta-row">
                            <td class="fi-ta-cell">
                                <button type="button" wire:click="openDetail('{{ $row->venta_id }}')" class="fi-link fi-link-size-sm px-3 py-3 text-primary-600 hover:underline dark:text-primary-400">
                                    {{ $row->venta_id }} · Ver
                                </button>
                            </td>
                            <td class="fi-ta-cell"><div class="px-3 py-3 text-sm whitespace-nowrap text-gray-950 dark:text-white">{{ $row->venta_fecha?->format('Y-m-d H:i:s') }}</div></td>
                            <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $row->local ?? '—' }}</div></td>
                            <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $row->cliente ?? '—' }}</div></td>
                            <td class="fi-ta-cell"><div class="px-3 py-3 text-sm whitespace-nowrap text-gray-950 dark:text-white">{{ trim(($row->comprobante_tipo ?? '').' '.($row->comprobante_serie ?? '').'-'.($row->comprobante_numero ?? ''), ' -') ?: '—' }}</div></td>
                            <td class="fi-ta-cell"><div class="opm-table-number px-3 py-3 text-sm text-gray-950 dark:text-white">{{ number_format((float) $row->subtotal, 2) }}</div></td>
                            <td class="fi-ta-cell"><div class="opm-table-number px-3 py-3 text-sm text-gray-950 dark:text-white">{{ number_format((float) $row->impuestos, 2) }}</div></td>
                            <td class="fi-ta-cell"><div class="opm-table-number px-3 py-3 text-sm font-semibold text-gray-950 dark:text-white">{{ number_format((float) $row->total, 2) }}</div></td>
                            <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $row->forma_pago ?? '—' }}</div></td>
                            <td class="fi-ta-cell"><div class="px-3 py-3"><span class="opm-status">{{ $row->estado ?? '—' }}</span></div></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">
            {{ $rows->onEachSide(1)->links() }}
        </div>
    </div>
@endif
