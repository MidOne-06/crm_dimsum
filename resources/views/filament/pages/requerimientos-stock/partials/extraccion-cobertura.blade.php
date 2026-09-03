@php($map = $this->coverageMap())
@php($gaps = $this->coverageGaps())
@php($today = now()->startOfDay())
@php($months = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Setiembre', 'Octubre', 'Noviembre', 'Diciembre'])

<x-filament::section>
    <x-slot name="heading">Todos los locales -- {{ $months[$coverageMonth - 1] }} {{ $coverageYear }}</x-slot>
    <x-slot name="description">De un vistazo, sin filtrar local por local: verde = completo, ámbar = con fallos, vacío = sin extraer.</x-slot>
    <x-slot name="afterHeader">
        <div class="flex items-center gap-2">
            <x-filament::icon-button icon="heroicon-o-chevron-left" label="Mes anterior" wire:click="coveragePrevMonth" size="sm" />
            <span class="w-32 text-center text-sm font-semibold text-gray-950 dark:text-white">{{ $months[$coverageMonth - 1] }} {{ $coverageYear }}</span>
            <x-filament::icon-button icon="heroicon-o-chevron-right" label="Mes siguiente" wire:click="coverageNextMonth" size="sm" />
        </div>
    </x-slot>

    @php($matrix = $this->coverageMatrix())
    @php($matrixMonthStart = \Illuminate\Support\Carbon::create($coverageYear, $coverageMonth, 1))
    @php($matrixDays = $matrixMonthStart->daysInMonth)

    <div class="overflow-x-auto">
        <table class="crm-coverage-matrix w-full border-collapse text-xs">
            <thead>
                <tr>
                    <th class="sticky start-0 z-10 bg-white px-2 py-1 text-start font-medium text-gray-500 dark:bg-gray-900 dark:text-gray-400">Local</th>
                    @for($day = 1; $day <= $matrixDays; $day++)
                        <th class="px-0.5 py-1 text-center font-normal text-gray-400" style="width:18px">{{ $day }}</th>
                    @endfor
                    <th class="px-2 py-1 text-end font-medium text-gray-500 dark:text-gray-400">%</th>
                </tr>
            </thead>
            <tbody>
                @foreach($locals as $local)
                    @php($localId = (string) $local['id'])
                    @php($row = $matrix[$localId] ?? [])
                    @php($fullCount = collect($row)->filter(fn ($s) => $s === 'full')->count())
                    @php($pct = $matrixDays > 0 ? (int) round(($fullCount / $matrixDays) * 100) : 0)
                    <tr class="border-t border-gray-100 dark:border-white/5">
                        <td class="sticky start-0 z-10 whitespace-nowrap bg-white px-2 py-0.5 text-gray-700 dark:bg-gray-900 dark:text-gray-200">
                            <button type="button" wire:click="$set('coverageLocalId', '{{ $localId }}')" class="hover:text-primary-600 hover:underline">{{ $local['name'] }}</button>
                        </td>
                        @for($day = 1; $day <= $matrixDays; $day++)
                            @php($date = \Illuminate\Support\Carbon::create($coverageYear, $coverageMonth, $day))
                            @php($status = $row[$date->toDateString()] ?? null)
                            @php($future = $date->greaterThan($today))
                            @php($color = $status === 'full' ? '#16a34a' : ($status === 'partial' ? '#d97706' : null))
                            <td class="p-0.5 text-center">
                                <span title="{{ $local['name'] }} · {{ $date->format('d/m/Y') }} · {{ $status === 'full' ? 'Completo' : ($status === 'partial' ? 'Con fallos' : 'Falta') }}" class="mx-auto block h-3.5 w-3.5 rounded-[3px] {{ $color ? '' : ($future ? 'bg-gray-50 dark:bg-white/5' : 'border border-gray-300 dark:border-gray-600') }}" style="{{ $color ? 'background:'.$color : '' }}"></span>
                            </td>
                        @endfor
                        <td class="px-2 py-0.5 text-end tabular-nums {{ $pct === 100 ? 'text-success-600 dark:text-success-400' : ($pct === 0 ? 'text-gray-400' : 'text-warning-600 dark:text-warning-400') }}">{{ $pct }}%</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-3 flex items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
        <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm" style="background:#16a34a"></span> Completo</span>
        <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm" style="background:#d97706"></span> Con fallos</span>
        <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm border border-gray-300 dark:border-gray-600"></span> Falta</span>
        <span class="ms-auto">Clic en un local para ver su calendario completo del año abajo.</span>
    </div>
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
