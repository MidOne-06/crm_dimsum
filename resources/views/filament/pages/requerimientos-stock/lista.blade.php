<x-filament-panels::page>
    <div class="space-y-5" wire:poll.60s="$refresh">
        @if ($loadError)
            <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger">
                <p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ $loadError }}</p>
            </x-filament::section>
        @endif

        {{ $this->table }}
    </div>
</x-filament-panels::page>
