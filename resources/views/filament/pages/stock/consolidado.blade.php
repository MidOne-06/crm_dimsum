<x-filament-panels::page>
    <div class="space-y-6">
        @if ($gatewayUnavailable)
            <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger">
                <p class="fi-in-text text-sm font-medium text-danger-600 dark:text-danger-400">{{ $filtersError }}</p>
            </x-filament::section>
        @endif

        @if ($resultError)
            <p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ $resultError }}</p>
        @endif

        @if ($hasSearched)
            <x-filament::section id="stock-results">
                {{ $this->table }}
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
