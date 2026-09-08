<x-filament-panels::page>
    <x-filament::section compact icon="heroicon-o-clock">
        <x-slot name="heading">Historial de canjes masivos</x-slot>
        <x-slot name="description">Vistas previas y confirmaciones registradas.</x-slot>

        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>
