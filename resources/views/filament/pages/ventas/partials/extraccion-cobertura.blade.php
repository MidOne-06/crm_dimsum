@php
    $coverageMap = $this->coverageMap();
    $coverageGaps = $this->coverageGaps();
    $today = now()->startOfDay();
    $monthNames = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Setiembre','Octubre','Noviembre','Diciembre'];
    $weekDays = ['L','M','X','J','V','S','D'];
    $totalGapDias = collect($coverageGaps)->sum(fn ($gap) => \Illuminate\Support\Carbon::parse($gap['start'])->diffInDays(\Illuminate\Support\Carbon::parse($gap['end'])) + 1);
@endphp

<x-filament::section>
    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <label class="w-full sm:w-72">
            <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Local</span>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="coverageLocalId">
                    @foreach ($availableLocals as $local)
                        <option value="{{ $local['id'] }}">{{ $local['name'] }}</option>
                    @endforeach
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

    <div class="grid gap-2 grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
        @for ($month = 1; $month <= 12; $month++)
            @php
                $first = \Illuminate\Support\Carbon::create($coverageYear, $month, 1);
                $daysInMonth = $first->daysInMonth;
                $startOffset = ($first->dayOfWeekIso - 1);
            @endphp
            <div class="rounded-md border border-gray-200 p-2 dark:border-white/10">
                <p class="mb-1 text-[11px] font-semibold text-gray-700 dark:text-gray-200">{{ $monthNames[$month - 1] }}</p>
                <div class="grid grid-cols-7 gap-[3px] text-center">
                    @foreach ($weekDays as $weekDay)
                        <span class="text-[9px] font-medium text-gray-400 dark:text-gray-500">{{ $weekDay }}</span>
                    @endforeach

                    @for ($i = 0; $i < $startOffset; $i++)
                        <span></span>
                    @endfor

                    @for ($day = 1; $day <= $daysInMonth; $day++)
                        @php
                            $date = \Illuminate\Support\Carbon::create($coverageYear, $month, $day);
                            $iso = $date->toDateString();
                            $status = $coverageMap[$iso] ?? null;
                            $isFuture = $date->greaterThan($today);
                            $bg = match (true) {
                                $isFuture => 'transparent',
                                $status === 'full' => '#16a34a',
                                $status === 'partial' => '#d97706',
                                default => null,
                            };
                        @endphp
                        <span
                            title="{{ $date->format('d/m/Y') }}{{ $status ? ' · '.($status === 'full' ? 'Completo' : 'Con ítems fallidos') : ($isFuture ? '' : ' · Falta extraer') }}"
                            class="flex h-4 w-4 items-center justify-center rounded-[3px] text-[9px] leading-none {{ $bg ? 'text-white' : ($isFuture ? 'text-gray-300 dark:text-gray-600' : 'border border-gray-300 text-gray-500 dark:border-gray-600 dark:text-gray-400') }}"
                            style="{{ $bg ? 'background:'.$bg : '' }}"
                        >{{ $day }}</span>
                    @endfor
                </div>
            </div>
        @endfor
    </div>

    <div class="mt-3 rounded-md bg-gray-50 p-3 text-sm dark:bg-white/5">
        @if (empty($coverageGaps))
            <p class="font-medium text-success-600 dark:text-success-400">{{ $coverageYear }} está completamente cubierto para este local (hasta hoy).</p>
        @else
            <p class="mb-1 font-medium text-gray-800 dark:text-gray-100">Faltan {{ $totalGapDias }} día(s) por extraer:</p>
            <ul class="grid list-inside list-disc gap-x-6 gap-y-0.5 text-gray-600 dark:text-gray-300 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($coverageGaps as $gap)
                    <li>{{ \Illuminate\Support\Carbon::parse($gap['start'])->format('d/m/Y') }} al {{ \Illuminate\Support\Carbon::parse($gap['end'])->format('d/m/Y') }}</li>
                @endforeach
            </ul>
        @endif
    </div>
</x-filament::section>
