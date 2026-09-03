<x-filament-panels::page>
    @if($listError)
        <x-filament::section class="mb-4">
            <p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ $listError }}</p>
        </x-filament::section>
    @endif
    {{ $this->table }}
</x-filament-panels::page>
