{{-- Selector de rango de fechas (doble calendario + atajos), reutilizado por
     Stock Actual y Ventas. El calendario y los atajos (Hoy/Ayer/Últimos 7
     días/...) se calculan en Alpine (cliente) y solo se sincronizan al
     servidor al presionar "Aplicar", vía el método Livewire indicado en
     $syncMethod (firma esperada: (string $start, string $end, string $preset = 'custom')). --}}
@php
    $initialPreset = $preset;
    $initialStart = $start;
    $initialEnd = $end;
@endphp

<div
    x-data="{
        open: false,
        preset: @js($initialPreset),
        appliedPreset: @js($initialPreset),
        rangeStart: @js($initialStart),
        rangeEnd: @js($initialEnd),
        tempStart: @js($initialStart),
        tempEnd: @js($initialEnd),
        leftMonth: 0, leftYear: 0, rightMonth: 0, rightYear: 0,
        monthNames: ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Setiembre','Octubre','Noviembre','Diciembre'],
        weekDays: ['Lu','Ma','Mi','Ju','Vi','Sa','Do'],
        presets: [
            {key: 'today', label: 'Hoy'},
            {key: 'yesterday', label: 'Ayer'},
            {key: 'last7', label: 'Últimos 7 días'},
            {key: 'last30', label: 'Últimos 30 días'},
            {key: 'month', label: 'Este mes'},
            {key: 'lastMonth', label: 'Mes pasado'},
        ],
        init() { this.syncCalendarsToRange(); },
        pad(n) { return String(n).padStart(2, '0'); },
        toIso(d) { return d.getFullYear() + '-' + this.pad(d.getMonth() + 1) + '-' + this.pad(d.getDate()); },
        fromIso(s) { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); },
        formatDisplay(s) {
            if (! s) return '';
            const d = this.fromIso(s);
            return this.pad(d.getDate()) + '/' + this.pad(d.getMonth() + 1) + '/' + d.getFullYear();
        },
        syncCalendarsToRange() {
            const base = this.tempStart ? this.fromIso(this.tempStart) : new Date();
            this.leftMonth = base.getMonth();
            this.leftYear = base.getFullYear();
            let rm = this.leftMonth + 1, ry = this.leftYear;
            if (rm > 11) { rm = 0; ry++; }
            this.rightMonth = rm;
            this.rightYear = ry;
        },
        openPicker() {
            this.tempStart = this.rangeStart;
            this.tempEnd = this.rangeEnd;
            this.preset = this.appliedPreset;
            this.syncCalendarsToRange();
            this.open = true;
        },
        prevMonth(side) {
            if (side === 'left') { this.leftMonth--; if (this.leftMonth < 0) { this.leftMonth = 11; this.leftYear--; } }
            else { this.rightMonth--; if (this.rightMonth < 0) { this.rightMonth = 11; this.rightYear--; } }
        },
        nextMonth(side) {
            if (side === 'left') { this.leftMonth++; if (this.leftMonth > 11) { this.leftMonth = 0; this.leftYear++; } }
            else { this.rightMonth++; if (this.rightMonth > 11) { this.rightMonth = 0; this.rightYear++; } }
        },
        buildDays(month, year) {
            const first = new Date(year, month, 1);
            const startOffset = (first.getDay() + 6) % 7;
            const daysInMonth = new Date(year, month + 1, 0).getDate();
            const cells = [];
            for (let i = 0; i < startOffset; i++) {
                const d = new Date(year, month, i - startOffset + 1);
                cells.push({ iso: this.toIso(d), day: d.getDate(), otherMonth: true });
            }
            for (let day = 1; day <= daysInMonth; day++) {
                cells.push({ iso: this.toIso(new Date(year, month, day)), day, otherMonth: false });
            }
            while (cells.length < 42) {
                const last = this.fromIso(cells[cells.length - 1].iso);
                const d = new Date(last.getFullYear(), last.getMonth(), last.getDate() + 1);
                cells.push({ iso: this.toIso(d), day: d.getDate(), otherMonth: true });
            }
            return cells;
        },
        selectDay(iso) {
            this.preset = 'custom';
            if (! this.tempStart || (this.tempStart && this.tempEnd)) {
                this.tempStart = iso;
                this.tempEnd = null;
            } else if (iso < this.tempStart) {
                this.tempEnd = this.tempStart;
                this.tempStart = iso;
            } else {
                this.tempEnd = iso;
            }
        },
        dayClasses(iso) {
            const isEndpoint = iso === this.tempStart || iso === this.tempEnd;
            const inRange = this.tempStart && this.tempEnd && iso > this.tempStart && iso < this.tempEnd;
            if (isEndpoint) return 'bg-primary-600 font-semibold text-white';
            if (inRange) return 'bg-primary-50 text-gray-950 dark:bg-primary-500/10 dark:text-white';
            return 'text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-white/5';
        },
        applyPreset(key) {
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            let start = new Date(today), end = new Date(today);
            if (key === 'yesterday') { start.setDate(start.getDate() - 1); end = new Date(start); }
            if (key === 'last7') { start.setDate(start.getDate() - 6); }
            if (key === 'last30') { start.setDate(start.getDate() - 29); }
            if (key === 'month') { start = new Date(today.getFullYear(), today.getMonth(), 1); }
            if (key === 'lastMonth') {
                start = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                end = new Date(today.getFullYear(), today.getMonth(), 0);
            }
            this.preset = key;
            this.tempStart = this.toIso(start);
            this.tempEnd = this.toIso(end);
            this.syncCalendarsToRange();
        },
        apply() {
            if (! this.tempStart) return;
            this.rangeStart = this.tempStart;
            this.rangeEnd = this.tempEnd || this.tempStart;
            this.appliedPreset = this.preset;
            $wire.call(@js($syncMethod), this.rangeStart, this.rangeEnd, this.appliedPreset);
            this.open = false;
        },
        cancelPicker() { this.open = false; },
    }"
    class="relative opm-date-range-control"
>
    <div x-on:click="open ? (open = false) : openPicker()">
        <x-filament::input.wrapper suffix-icon="heroicon-o-calendar-days">
            <x-filament::input
                type="text"
                readonly
                x-bind:value="formatDisplay(rangeStart) + ' al ' + formatDisplay(rangeEnd)"
                class="cursor-pointer"
            />
        </x-filament::input.wrapper>
    </div>

    <div
        x-show="open"
        x-cloak
        x-on:click.outside="cancelPicker"
        x-transition
        class="opm-date-picker absolute start-0 top-full z-50 mt-2 flex flex-col overflow-hidden rounded-xl bg-white shadow-lg ring-1 ring-gray-950/5 sm:flex-row dark:bg-gray-900 dark:ring-white/10"
    >
        <div class="flex shrink-0 flex-row gap-1 overflow-x-auto border-b border-gray-200 p-3 sm:w-40 sm:flex-col sm:overflow-visible sm:border-b-0 sm:border-e dark:border-white/10">
            <template x-for="item in presets" :key="item.key">
                <button
                    type="button"
                    x-on:click="applyPreset(item.key)"
                    x-text="item.label"
                    :class="preset === item.key
                        ? 'bg-primary-50 font-medium text-primary-600 dark:bg-primary-500/10 dark:text-primary-400'
                        : 'text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5'"
                    class="whitespace-nowrap rounded-md px-3 py-2 text-start text-sm"
                ></button>
            </template>
            <span
                x-text="'Otra'"
                :class="preset === 'custom'
                    ? 'bg-primary-50 font-medium text-primary-600 dark:bg-primary-500/10 dark:text-primary-400'
                    : 'text-gray-400 dark:text-gray-500'"
                class="whitespace-nowrap rounded-md px-3 py-2 text-start text-sm"
            ></span>
        </div>

        <div class="flex flex-1 flex-col">
            <div class="flex flex-col gap-6 p-4 sm:flex-row">
                <template x-for="side in ['left', 'right']" :key="side">
                    <div class="w-full sm:w-60">
                        <div class="mb-2 flex items-center justify-between gap-1">
                            <button type="button" x-on:click="prevMonth(side)" x-bind:aria-label="'Mes anterior (' + (side === 'left' ? 'calendario inicial' : 'calendario final') + ')'" class="fi-icon-btn rounded-md p-1 text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/5">
                                <x-filament::icon icon="heroicon-o-chevron-left" class="h-4 w-4" />
                            </button>
                            <div class="flex flex-1 gap-1">
                                <select
                                    x-bind:value="side === 'left' ? leftMonth : rightMonth"
                                    x-on:change="side === 'left' ? leftMonth = Number($event.target.value) : rightMonth = Number($event.target.value)"
                                    class="fi-select-input block w-full rounded-md border-gray-300 py-1 text-xs dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                                >
                                    <template x-for="(name, index) in monthNames" :key="index">
                                        <option :value="index" x-text="name"></option>
                                    </template>
                                </select>
                                <select
                                    x-bind:value="side === 'left' ? leftYear : rightYear"
                                    x-on:change="side === 'left' ? leftYear = Number($event.target.value) : rightYear = Number($event.target.value)"
                                    class="fi-select-input block rounded-md border-gray-300 py-1 text-xs dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                                >
                                    <template x-for="y in [leftYear - 2, leftYear - 1, leftYear, leftYear + 1, leftYear + 2]" :key="y">
                                        <option :value="y" x-text="y"></option>
                                    </template>
                                </select>
                            </div>
                            <button type="button" x-on:click="nextMonth(side)" x-bind:aria-label="'Mes siguiente (' + (side === 'left' ? 'calendario inicial' : 'calendario final') + ')'" class="fi-icon-btn rounded-md p-1 text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/5">
                                <x-filament::icon icon="heroicon-o-chevron-right" class="h-4 w-4" />
                            </button>
                        </div>
                        <div class="grid grid-cols-7 gap-y-1 text-center text-xs">
                            <template x-for="d in weekDays" :key="d">
                                <span x-text="d" class="py-1 font-medium text-gray-400 dark:text-gray-500"></span>
                            </template>
                            <template x-for="cell in buildDays(side === 'left' ? leftMonth : rightMonth, side === 'left' ? leftYear : rightYear)" :key="cell.iso">
                                <button
                                    type="button"
                                    x-on:click="selectDay(cell.iso)"
                                    x-text="cell.day"
                                    :class="cell.otherMonth ? 'text-gray-300 dark:text-gray-600' : dayClasses(cell.iso)"
                                    class="rounded-md py-1.5 text-sm"
                                ></button>
                            </template>
                        </div>
                    </div>
                </template>
            </div>

            <div class="flex items-center justify-between gap-3 border-t border-gray-200 p-3 dark:border-white/10">
                <span class="text-sm text-gray-500 dark:text-gray-400" x-text="formatDisplay(tempStart) + ' al ' + formatDisplay(tempEnd || tempStart)"></span>
                <div class="flex gap-2">
                    <x-filament::button type="button" color="gray" size="sm" x-on:click="cancelPicker">Cancelar</x-filament::button>
                    <x-filament::button type="button" size="sm" x-on:click="apply">Aplicar</x-filament::button>
                </div>
            </div>
        </div>
    </div>
</div>
