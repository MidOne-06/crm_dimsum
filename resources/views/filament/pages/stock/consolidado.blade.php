<x-filament-panels::page>
    <div
        class="space-y-6"
        x-data
        x-on:stock-results-ready.window="$nextTick(() => document.getElementById('stock-results')?.scrollIntoView({ behavior: 'smooth', block: 'start' }))"
    >

        @include('filament.pages.stock.partials.filters-form')

        @if ($hasSearched)
            <x-filament::section id="stock-results">
                {{ $this->table }}
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
