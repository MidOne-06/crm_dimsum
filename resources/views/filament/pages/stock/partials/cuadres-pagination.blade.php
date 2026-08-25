@php($pages = max(1, (int) ceil($cuadresTotal / max(1, $cuadresRegistros))))

<nav class="fi-pagination mt-3" aria-label="Paginación de cuadres">
    @if ($cuadresPagina > 1)
        <x-filament::button type="button" size="sm" color="gray" class="fi-pagination-previous-btn" wire:click="goToCuadresPage(-1)">
            Anterior
        </x-filament::button>
    @endif
    <span class="fi-pagination-overview text-sm text-gray-500 dark:text-gray-400" aria-live="polite">
        @if ($cuadresTotal)
            Se muestran {{ (($cuadresPagina - 1) * $cuadresRegistros) + 1 }} a {{ min($cuadresPagina * $cuadresRegistros, $cuadresTotal) }} de {{ $cuadresTotal }} resultados
        @else
            0 resultados
        @endif
    </span>
    <div class="fi-pagination-records-per-page-select-ctn">
        <label class="fi-pagination-records-per-page-select">
            <x-filament::input.wrapper prefix="Por página">
                <x-filament::input.select wire:model.live="cuadresRegistros">
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>
    </div>
    @if ($cuadresPagina < $pages)
        <x-filament::button type="button" size="sm" color="gray" class="fi-pagination-next-btn" wire:click="goToCuadresPage(1)">
            Siguiente
        </x-filament::button>
    @endif


    @include('filament.pages.stock.partials.pagination-pages', [
        'paginationCurrent' => $cuadresPagina,
        'paginationPages' => $pages,
        'paginationAction' => 'goToCuadresPage',
    ])
</nav>
