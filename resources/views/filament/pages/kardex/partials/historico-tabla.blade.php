@php($rows = $this->rows())

@if ($rows->isEmpty())
    <x-filament::empty-state icon="heroicon-o-archive-box" heading="No hay movimientos guardados para los filtros seleccionados." />
@else
    <div class="opm-stock-table">
        <div class="fi-ta-content overflow-x-auto">
            <table class="fi-ta-table w-full text-start">
                <thead>
                    <tr>
                        <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Fecha</span></th>
                        <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Local</span></th>
                        <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Almacén</span></th>
                        <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Producto</span></th>
                        <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Motivo</span></th>
                        <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Entrada</span></th>
                        <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Salida</span></th>
                        <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Stock</span></th>
                        <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Stock valorizado</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr class="fi-ta-row">
                            <td class="fi-ta-cell"><div class="px-3 py-3 text-sm whitespace-nowrap text-gray-950 dark:text-white">{{ $row->fecha_hora?->format('d/m/Y H:i') ?? $row->fecha?->format('d/m/Y') }}</div></td>
                            <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $row->local_nombre ?? $row->local_id }}</div></td>
                            <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $row->almacen ?? '—' }}</div></td>
                            <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $row->item_nombre ?? '—' }}</div></td>
                            <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $row->motivo ?? '—' }}</div></td>
                            <td class="fi-ta-cell"><div class="opm-table-number px-3 py-3 text-sm font-medium text-success-600 dark:text-success-400">{{ $row->entrada > 0 ? number_format((float) $row->entrada, 3) : '' }}</div></td>
                            <td class="fi-ta-cell"><div class="opm-table-number px-3 py-3 text-sm font-medium text-danger-600 dark:text-danger-400">{{ $row->salida > 0 ? number_format((float) $row->salida, 3) : '' }}</div></td>
                            <td class="fi-ta-cell"><div class="opm-table-number px-3 py-3 text-sm text-gray-950 dark:text-white">{{ number_format((float) $row->stock, 3) }}</div></td>
                            <td class="fi-ta-cell"><div class="opm-table-number px-3 py-3 text-sm text-gray-950 dark:text-white">{{ number_format((float) $row->stock_valorizado, 2) }}</div></td>
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
