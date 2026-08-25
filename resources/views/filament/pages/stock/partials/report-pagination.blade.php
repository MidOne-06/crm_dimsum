<nav class="fi-pagination mt-3" aria-label="Paginación del reporte">
    @if ($page['page'] > 1)
        <x-filament::button type="button" size="sm" color="gray" class="fi-pagination-previous-btn" wire:click="goToReportPage(-1)">
            Anterior
        </x-filament::button>
    @endif
    <span class="fi-pagination-overview text-sm text-gray-500 dark:text-gray-400" aria-live="polite">
        @if ($page['total'])
            Se muestran {{ (($page['page'] - 1) * $reportPageSize) + 1 }} a {{ min($page['page'] * $reportPageSize, $page['total']) }} de {{ $page['total'] }} resultados
        @else
            0 resultados
        @endif
    </span>
    <div class="fi-pagination-records-per-page-select-ctn">
        <label class="fi-pagination-records-per-page-select">
            <x-filament::input.wrapper prefix="Por página">
                <x-filament::input.select wire:model.live="reportPageSize">
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>
    </div>
    @if ($page['page'] < $page['pages'])
        <x-filament::button type="button" size="sm" color="gray" class="fi-pagination-next-btn" wire:click="goToReportPage(1)">
            Siguiente
        </x-filament::button>
    @endif

    @include('filament.pages.stock.partials.pagination-pages', [
        'paginationCurrent' => $page['page'],
        'paginationPages' => $page['pages'],
        'paginationAction' => 'goToReportPage',
    ])
</nav>
