@php
    $visiblePages = collect([
        1,
        $paginationCurrent - 1,
        $paginationCurrent,
        $paginationCurrent + 1,
        $paginationPages,
    ])->filter(fn (int $page): bool => $page >= 1 && $page <= $paginationPages)
        ->unique()
        ->sort()
        ->values();
    $previousVisiblePage = null;
@endphp

@if ($paginationPages > 1)
    <ol class="fi-pagination-items">
        @if ($paginationCurrent > 1)
            <li class="fi-pagination-item">
                <button type="button" class="fi-pagination-item-btn" wire:click="{{ $paginationAction }}(-1)" aria-label="Página anterior">
                    <x-filament::icon icon="heroicon-m-chevron-left" class="fi-pagination-item-icon h-5 w-5" />
                </button>
            </li>
        @endif

        @foreach ($visiblePages as $visiblePage)
            @if ($previousVisiblePage !== null && $visiblePage > ($previousVisiblePage + 1))
                <li class="fi-pagination-item fi-disabled">
                    <button type="button" class="fi-pagination-item-btn" disabled aria-hidden="true">
                        <span class="fi-pagination-item-label">…</span>
                    </button>
                </li>
            @endif

            <li @class(['fi-pagination-item', 'fi-active' => $visiblePage === $paginationCurrent])>
                <button
                    type="button"
                    class="fi-pagination-item-btn"
                    wire:click="{{ $paginationAction }}({{ $visiblePage - $paginationCurrent }})"
                    @if ($visiblePage === $paginationCurrent) aria-current="page" @endif
                    aria-label="Ir a la página {{ $visiblePage }}"
                >
                    <span class="fi-pagination-item-label">{{ $visiblePage }}</span>
                </button>
            </li>

            @php($previousVisiblePage = $visiblePage)
        @endforeach

        @if ($paginationCurrent < $paginationPages)
            <li class="fi-pagination-item">
                <button type="button" class="fi-pagination-item-btn" wire:click="{{ $paginationAction }}(1)" aria-label="Página siguiente">
                    <x-filament::icon icon="heroicon-m-chevron-right" class="fi-pagination-item-icon h-5 w-5" />
                </button>
            </li>
        @endif
    </ol>
@endif
