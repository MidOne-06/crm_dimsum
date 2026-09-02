<x-filament-panels::page>
    <x-filament::section>
        <form wire:submit.prevent="guardar" class="space-y-4">
            {{ $this->form }}
            <div class="flex justify-end">
                <x-filament::button type="submit" icon="heroicon-m-check">Guardar</x-filament::button>
            </div>
        </form>
    </x-filament::section>

    <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
        Si eliges "Lista manual de prioridad por local", el orden real se define en
        <a href="{{ \App\Filament\Resources\PrioridadLocalProrrateoResource::getUrl() }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">Prioridad manual de reparto</a>.
    </p>
</x-filament-panels::page>
