<x-filament-panels::page>
    <div
        class="space-y-6"
        x-data
        x-on:stock-results-ready.window="$nextTick(() => document.getElementById('stock-results')?.scrollIntoView({ behavior: 'smooth', block: 'start' }))"
    >

        @include('filament.pages.stock.partials.filters-form')

        @if ($hasSearched)
            <div class="grid gap-4 sm:grid-cols-3">
                <x-filament::section compact class="opm-kpi-card" style="--opm-kpi-color: #64748b">
                    <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Cantidad de cuadres</span>
                    <p class="text-2xl font-semibold text-gray-950 dark:text-white">{{ $cuadresHeader['totalCuadres'] ?? $cuadresHeader['totalcuadres'] ?? $cuadresTotal }}</p>
                </x-filament::section>
                <x-filament::section compact class="opm-kpi-card" style="--opm-kpi-color: #16a34a">
                    <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Sobrevalorización</span>
                    <p class="text-2xl font-semibold text-success-600 dark:text-success-400">{{ number_format((float) ($cuadresHeader['cuadremanual_sobrevalorizacion'] ?? $cuadresHeader['sobrevalorizacion'] ?? 0), 2) }}</p>
                </x-filament::section>
                <x-filament::section compact class="opm-kpi-card" style="--opm-kpi-color: #dc2626">
                    <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Pérdida</span>
                    <p class="text-2xl font-semibold text-danger-600 dark:text-danger-400">{{ number_format((float) ($cuadresHeader['cuadremanual_perdida'] ?? $cuadresHeader['perdida'] ?? 0), 2) }}</p>
                </x-filament::section>
            </div>

            <x-filament::section id="stock-results">
                <x-slot name="heading">Resultados</x-slot>

                @if (count($cuadresRows))
                    <div class="opm-stock-table">
                    <div class="fi-ta-content overflow-x-auto">
                        <table class="fi-ta-table w-full text-start">
                            <thead>
                                <tr>
                                    <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Cód.</span></th>
                                    <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Fecha</span></th>
                                    <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Local</span></th>
                                    <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Sobrevalorización</span></th>
                                    <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Pérdida</span></th>
                                    <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Motivo</span></th>
                                    <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Responsable</span></th>
                                    <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Tipo</span></th>
                                    <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Estado</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($cuadresRows as $row)
                                    <tr class="fi-ta-row">
                                        <td class="fi-ta-cell">
                                            @if (auth()->user()?->hasPermission('stock.actual.ver-detalle'))
                                                <button type="button" wire:click="openDetail('{{ $row['cuadremanual_id'] }}')" class="fi-link fi-link-size-sm inline-flex items-center gap-1 text-primary-600 hover:underline dark:text-primary-400">
                                                    {{ $row['cuadremanual_id'] }} · Ver
                                                </button>
                                            @else
                                                <span class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $row['cuadremanual_id'] }}</span>
                                            @endif
                                        </td>
                                        <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $row['cuadremanual_fecha'] ?? '—' }}</div></td>
                                        <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $row['local_descripcion'] ?? $row['cuadremanual_local'] ?? '—' }}</div></td>
                                        <td class="fi-ta-cell"><div class="opm-table-number px-3 py-3 text-sm text-success-600 dark:text-success-400">{{ $row['sobrevalorizacion'] ?? '—' }}</div></td>
                                        <td class="fi-ta-cell"><div class="opm-table-number px-3 py-3 text-sm text-danger-600 dark:text-danger-400">{{ $row['perdida'] ?? '—' }}</div></td>
                                        <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $row['cuadremanual_razon'] ?? $row['motivo'] ?? '—' }}</div></td>
                                        <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $row['usuario_nombre'] ?? ($row['usuario']['usuario_nombres'] ?? $row['responsable'] ?? '—') }}</div></td>
                                        <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $row['tipo_cuadre'] ?? $row['tipo'] ?? '—' }}</div></td>
                                        <td class="fi-ta-cell"><div class="px-3 py-3"><span class="opm-status">{{ $row['estado'] ?? 'Sin estado' }}</span></div></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @include('filament.pages.stock.partials.cuadres-pagination')
                    </div>
                @else
                    <x-filament::empty-state icon="heroicon-o-inbox" heading="No hay registros para los filtros seleccionados." />
                @endif
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Maestro operativo</x-slot>

                @include('filament.pages.stock.partials.report-filters', ['showAlmacenFilter' => true])

                @php($page = $this->masterPage())
                @if (count($page['rows']))
                    <div class="opm-stock-table">
                    <div class="fi-ta-content overflow-x-auto">
                        <table class="fi-ta-table w-full text-start">
                            <thead>
                                <tr>
                                    <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Local</span></th>
                                    <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Almacén</span></th>
                                    <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Ítem</span></th>
                                    <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Tipo</span></th>
                                    <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Último cuadre</span></th>
                                    <th class="fi-ta-header-cell"><span class="fi-ta-header-cell-label">Stock actual</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($page['rows'] as $row)
                                    <tr class="fi-ta-row">
                                        <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $row['local'] ?? '—' }}</div></td>
                                        <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $row['almacen'] ?? '—' }}</div></td>
                                        <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $row['item'] ?? '—' }}</div></td>
                                        <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $row['tipo'] ?? '—' }}</div></td>
                                        <td class="fi-ta-cell"><div class="px-3 py-3 text-sm text-gray-950 dark:text-white">{{ $row['fecha'] ?? '—' }}</div></td>
                                        <td class="fi-ta-cell"><div class="opm-table-number px-3 py-3 text-sm font-medium text-gray-950 dark:text-white">{{ number_format((float) ($row['stockActual'] ?? 0), 3) }} {{ $row['unidad'] ?? '' }}</div></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @include('filament.pages.stock.partials.report-pagination', ['page' => $page])
                    </div>
                @else
                    <x-filament::empty-state icon="heroicon-o-cube" heading="No hay stock para los filtros consolidados seleccionados." />
                @endif
            </x-filament::section>
        @endif
    </div>

    <x-filament::modal id="stock-detail-modal" width="7xl" sticky-header sticky-footer class="opm-detail-modal">
        <x-slot name="heading">
            Detalle de cuadre manual {{ $detail['id'] ?? '' ? '#'.$detail['id'] : '' }}
        </x-slot>

        @if ($detailLoading)
            <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                <x-filament::loading-indicator class="h-4 w-4" />
                Cargando detalle desde Restaurant.pe…
            </div>
        @elseif ($detailError)
            <p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ $detailError }}</p>
        @elseif ($detail)
            <div class="opm-detail-summary">
                <div><span>Responsable</span><strong>{{ $detail['registradoPor'] ?: '—' }}</strong></div>
                <div><span>Local</span><strong>{{ $detail['local'] ?? '—' }}</strong></div>
                <div><span>Registro</span><strong>{{ $detail['fechaRegistro'] ?? '—' }}</strong></div>
                <div><span>Cuadre</span><strong>{{ $detail['fechaCuadre'] ?? '—' }}</strong></div>
                <div><span>Ítems</span><strong>{{ count($detail['items'] ?? []) }}</strong></div>
            </div>

            @if (count($detail['items'] ?? []))
                @php($detailItemsPage = $this->detailItemsPage())
                <div class="opm-detail-list">
                    <div class="opm-detail-grid-header" aria-hidden="true">
                        <span>Ítem</span>
                        <span>Almacén</span>
                        <span>Aumento</span>
                        <span>Disminución</span>
                        <span>Costo</span>
                        <span>Impuestos</span>
                        <span>Total</span>
                        <span>Stock anterior</span>
                        <span>Stock actual</span>
                        <span>Valorización</span>
                    </div>
                    @foreach ($detailItemsPage['rows'] as $item)
                        @php($valuation = (float) ($item['valorizacion'] ?? 0))
                        <article class="opm-detail-item">
                            <header>
                                <div>
                                    <strong>{{ $item['item'] ?? '—' }}</strong>
                                    <span>{{ $item['tipo'] ?? '—' }}</span>
                                </div>
                                <span>{{ $item['almacen'] ?? '—' }}</span>
                            </header>
                            <dl>
                                <div><dt>Aumento</dt><dd class="text-success-600 dark:text-success-400">{{ number_format((float) ($item['aumento'] ?? 0), 3) }} {{ $item['unidad'] ?? '' }}</dd></div>
                                <div><dt>Disminución</dt><dd class="text-danger-600 dark:text-danger-400">{{ number_format((float) ($item['disminuyo'] ?? 0), 3) }} {{ $item['unidad'] ?? '' }}</dd></div>
                                <div><dt>Costo</dt><dd>{{ number_format((float) ($item['costo'] ?? 0), 2) }}</dd></div>
                                <div><dt>Impuestos</dt><dd>{{ number_format((float) ($item['impuestos'] ?? 0), 2) }}</dd></div>
                                <div><dt>Total</dt><dd>{{ number_format((float) ($item['total'] ?? 0), 2) }}</dd></div>
                                <div><dt>Stock anterior</dt><dd>{{ number_format((float) ($item['stockAnterior'] ?? 0), 3) }} {{ $item['unidad'] ?? '' }}</dd></div>
                                <div><dt>Stock actual</dt><dd>{{ number_format((float) ($item['stockActual'] ?? 0), 3) }} {{ $item['unidad'] ?? '' }}</dd></div>
                                <div><dt>Valorización</dt><dd @class(['text-success-600 dark:text-success-400' => $valuation > 0, 'text-danger-600 dark:text-danger-400' => $valuation < 0])>{{ $valuation ? ($valuation > 0 ? '+' : '−').' '.number_format(abs($valuation), 2) : '—' }}</dd></div>
                            </dl>
                        </article>
                    @endforeach

                    <nav class="fi-pagination" aria-label="Paginación del detalle">
                        @if ($detailItemsPage['page'] > 1)
                            <x-filament::button type="button" size="sm" color="gray" class="fi-pagination-previous-btn" wire:click="goToDetailPage(-1)">Anterior</x-filament::button>
                        @endif
                        <span class="fi-pagination-overview">
                            Se muestran {{ (($detailItemsPage['page'] - 1) * $detailPageSize) + 1 }} a {{ min($detailItemsPage['page'] * $detailPageSize, $detailItemsPage['total']) }} de {{ $detailItemsPage['total'] }} resultados
                        </span>
                        <div class="fi-pagination-records-per-page-select-ctn">
                            <label class="fi-pagination-records-per-page-select">
                                <x-filament::input.wrapper prefix="Por página">
                                    <x-filament::input.select wire:model.live="detailPageSize">
                                        <option value="10">10</option>
                                        <option value="25">25</option>
                                        <option value="50">50</option>
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                            </label>
                        </div>
                        @if ($detailItemsPage['page'] < $detailItemsPage['pages'])
                            <x-filament::button type="button" size="sm" color="gray" class="fi-pagination-next-btn" wire:click="goToDetailPage(1)">Siguiente</x-filament::button>
                        @endif
                        @include('filament.pages.stock.partials.pagination-pages', [
                            'paginationCurrent' => $detailItemsPage['page'],
                            'paginationPages' => $detailItemsPage['pages'],
                            'paginationAction' => 'goToDetailPage',
                        ])
                    </nav>
                </div>
            @else
                <x-filament::empty-state icon="heroicon-o-inbox" heading="Este cuadre no tiene ítems." />
            @endif
        @endif

        <x-slot name="footerActions">
            <x-filament::button color="gray" wire:click="closeDetail">Cerrar</x-filament::button>
        </x-slot>
    </x-filament::modal>
</x-filament-panels::page>
