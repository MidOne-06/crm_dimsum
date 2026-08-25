<nav class="fi-pagination" aria-label="Paginación de ventas">
    <span class="fi-pagination-overview text-sm text-gray-500 dark:text-gray-400" aria-live="polite">
        @if ($salesTotal)
            Se muestran {{ (($salesPage - 1) * $salesPageSize) + 1 }} a {{ min($salesPage * $salesPageSize, $salesTotal) }} de {{ $salesTotal }} resultados
        @else
            0 resultados
        @endif
    </span>

    <div class="fi-pagination-records-per-page-select-ctn">
        <label class="fi-pagination-records-per-page-select">
            <x-filament::input.wrapper prefix="Por página">
                <x-filament::input.select wire:model.live="salesPageSize">
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>
    </div>

    @include('filament.pages.stock.partials.pagination-pages', [
        'paginationCurrent' => $salesPage,
        'paginationPages' => $salesPages,
        'paginationAction' => 'goToSalesPage',
    ])
</nav>
