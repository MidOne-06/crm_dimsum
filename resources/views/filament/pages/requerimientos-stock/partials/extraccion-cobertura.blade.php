@php($map = $this->coverageMap())
@php($gaps = $this->coverageGaps())
@php($today = now()->startOfDay())
@php($months = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Setiembre', 'Octubre', 'Noviembre', 'Diciembre'])

@php($resumenCobertura = $this->coverageSummary())

<x-filament::section>
    <x-slot name="heading">Resumen -- {{ $months[$coverageMonth - 1] }} {{ $coverageYear }}</x-slot>
    <x-slot name="afterHeader">
        <div class="flex items-center gap-2">
            <x-filament::icon-button icon="heroicon-o-chevron-left" label="Mes anterior" wire:click="coveragePrevMonth" size="sm" />
            <span class="w-32 text-center text-sm font-semibold text-gray-950 dark:text-white">{{ $months[$coverageMonth - 1] }} {{ $coverageYear }}</span>
            <x-filament::icon-button icon="heroicon-o-chevron-right" label="Mes siguiente" wire:click="coverageNextMonth" size="sm" />
        </div>
    </x-slot>

    @if($resumenCobertura['conProblemas']->isEmpty())
        <p class="text-sm font-medium text-success-600 dark:text-success-400">Los {{ $resumenCobertura['total'] }} locales están al día en {{ $months[$coverageMonth - 1] }} (hasta hoy).</p>
    @else
        <p class="mb-3 text-sm text-gray-600 dark:text-gray-300">{{ $resumenCobertura['total'] - $resumenCobertura['conProblemas']->count() }} de {{ $resumenCobertura['total'] }} locales al día. Con pendientes:</p>
        <ul class="divide-y divide-gray-100 dark:divide-white/5">
            @foreach($resumenCobertura['conProblemas'] as $item)
                <li class="flex items-center justify-between gap-3 py-1.5 text-sm">
                    <button type="button" wire:click="$set('coverageLocalId', '{{ $item['id'] }}')" class="font-medium text-gray-800 hover:text-primary-600 hover:underline dark:text-gray-100">{{ $item['name'] }}</button>
                    <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">
                        @if($item['partial'] > 0)<span class="text-warning-600 dark:text-warning-400">{{ $item['partial'] }} con fallos</span>@endif
                        @if($item['partial'] > 0 && $item['missing'] > 0) · @endif
                        @if($item['missing'] > 0)<span>{{ $item['missing'] }} sin extraer</span>@endif
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</x-filament::section>

<x-filament::section>
    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <label class="w-full sm:w-72">
            <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Local</span>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="coverageLocalId">
                    @foreach($locals as $local)<option value="{{ $local['id'] }}">{{ $local['name'] }}</option>@endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>
        <div class="flex items-center gap-2">
            <x-filament::icon-button icon="heroicon-o-chevron-left" label="Año anterior" wire:click="coveragePrevYear" size="sm" />
            <span class="w-12 text-center text-sm font-semibold text-gray-950 dark:text-white">{{ $coverageYear }}</span>
            <x-filament::icon-button icon="heroicon-o-chevron-right" label="Año siguiente" wire:click="coverageNextYear" size="sm" />
        </div>
        <div class="flex items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
            <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm" style="background:#16a34a"></span> Completo</span>
            <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm" style="background:#d97706"></span> Con fallos</span>
            <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm border border-gray-300 dark:border-gray-600"></span> Falta</span>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
        @for($month = 1; $month <= 12; $month++)
            @php($first = \Illuminate\Support\Carbon::create($coverageYear, $month, 1))
            <div class="rounded-md border border-gray-200 p-2 dark:border-white/10">
                <p class="mb-1 text-[11px] font-semibold text-gray-700 dark:text-gray-200">{{ $months[$month - 1] }}</p>
                <div class="grid grid-cols-7 gap-[3px] text-center">
                    @foreach(['L', 'M', 'X', 'J', 'V', 'S', 'D'] as $weekday)<span class="text-[9px] text-gray-400">{{ $weekday }}</span>@endforeach
                    @for($i = 0; $i < $first->dayOfWeekIso - 1; $i++)<span></span>@endfor
                    @for($day = 1; $day <= $first->daysInMonth; $day++)
                        @php($date = \Illuminate\Support\Carbon::create($coverageYear, $month, $day))
                        @php($status = $map[$date->toDateString()] ?? null)
                        @php($future = $date->greaterThan($today))
                        @php($color = $status === 'full' ? '#16a34a' : ($status === 'partial' ? '#d97706' : null))
                        <span title="{{ $date->format('d/m/Y') }}" class="flex h-4 w-4 items-center justify-center rounded-[3px] text-[9px] {{ $color ? 'text-white' : ($future ? 'text-gray-300 dark:text-gray-600' : 'border border-gray-300 text-gray-500 dark:border-gray-600') }}" style="{{ $color ? 'background:'.$color : '' }}">{{ $day }}</span>
                    @endfor
                </div>
            </div>
        @endfor
    </div>

    <div class="mt-3 rounded-md bg-gray-50 p-3 text-sm dark:bg-white/5">
        @if(empty($gaps))
            <p class="font-medium text-success-600 dark:text-success-400">{{ $coverageYear }} está completamente cubierto para este local (hasta hoy).</p>
        @else
            <p class="font-medium text-gray-800 dark:text-gray-100">Hay {{ count($gaps) }} rango(s) pendiente(s) de extraer para este local.</p>
        @endif
    </div>
</x-filament::section>
