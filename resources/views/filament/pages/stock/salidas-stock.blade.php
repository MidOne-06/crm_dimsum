<x-filament-panels::page>
    <x-filament::section>
        <div class="grid items-end gap-4 xl:grid-cols-[minmax(19rem,1.4fr)_minmax(13rem,1fr)_minmax(13rem,1fr)_auto]">
            <x-filament::fieldset label="Fecha">
                @include('filament.components.date-range-picker', [
                    'start' => $desde,
                    'end' => $hasta,
                    'preset' => $activeDatePreset,
                    'syncMethod' => 'setDateRange',
                ])
            </x-filament::fieldset>
            <x-filament::fieldset label="Local"><x-filament::input.wrapper><x-filament::input.select wire:model="local"><option value="">Todos los locales</option>@foreach ($this->locales() as $id => $nombre)<option value="{{ $id }}">{{ $nombre }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></x-filament::fieldset>
            <x-filament::fieldset label="Categoría"><x-filament::input.wrapper><x-filament::input.select wire:model="categoria"><option value="">Todas las categorías</option>@foreach ($this->categorias() as $nombre)<option value="{{ $nombre }}">{{ $nombre }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></x-filament::fieldset>
            <x-filament::button wire:click="aplicar" icon="heroicon-o-magnifying-glass">Consultar</x-filament::button>
        </div>
    </x-filament::section>
    {{ $this->table }}
</x-filament-panels::page>
