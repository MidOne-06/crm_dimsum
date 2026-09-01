<x-filament-panels::page>
    <x-filament::section>
        <div class="grid items-end gap-4 xl:grid-cols-[minmax(19rem,1.4fr)_minmax(13rem,1fr)_minmax(11rem,.8fr)_auto_auto]">
            <x-filament::fieldset label="Fecha">@include('filament.components.date-range-picker',['start'=>$desde,'end'=>$hasta,'preset'=>$activeDatePreset,'syncMethod'=>'setDateRange'])</x-filament::fieldset>
            <x-filament::fieldset label="Origen"><x-filament::input.wrapper><x-filament::input.select wire:model="local"><option value="">Todos los locales</option>@foreach($this->locales() as $id=>$nombre)<option value="{{ $id }}">{{ $nombre }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></x-filament::fieldset>
            <x-filament::fieldset label="Estado"><x-filament::input.wrapper><x-filament::input.select wire:model="estado"><option value="">Todos</option><option value="1">Activa</option><option value="0">Anulada</option></x-filament::input.select></x-filament::input.wrapper></x-filament::fieldset>
            <x-filament::button wire:click="aplicar" icon="heroicon-o-magnifying-glass">Consultar</x-filament::button>
            @if(auth()->user()?->hasPermission('guias-internas.sincronizar'))<x-filament::button tag="a" href="{{ \App\Filament\Pages\Stock\ExtraccionGuiasInternas::getUrl() }}" icon="heroicon-o-arrow-down-tray" color="gray">Extracción</x-filament::button>@endif
        </div>
    </x-filament::section>
    {{ $this->table }}
</x-filament-panels::page>
