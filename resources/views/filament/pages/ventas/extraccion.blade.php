@php($resumen = $this->resumenGeneral())

<x-filament-panels::page>
    <div class="space-y-4" x-data="{ tab: 'nueva' }">
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <x-filament::section compact class="opm-kpi-card" style="--opm-kpi-color: #64748b">
                <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Ventas guardadas</span>
                <p class="text-xl font-semibold text-gray-950 dark:text-white">{{ number_format($resumen['ventas']) }}</p>
            </x-filament::section>
            <x-filament::section compact class="opm-kpi-card" style="--opm-kpi-color: #3e86d8">
                <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Corridas totales</span>
                <p class="text-xl font-semibold text-gray-950 dark:text-white">{{ number_format($resumen['corridas']) }}</p>
            </x-filament::section>
            <x-filament::section compact class="opm-kpi-card" style="--opm-kpi-color: #dc2626">
                <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Corridas fallidas</span>
                <p class="text-xl font-semibold text-danger-600 dark:text-danger-400">{{ number_format($resumen['fallidas']) }}</p>
            </x-filament::section>
            <x-filament::section compact class="opm-kpi-card" style="--opm-kpi-color: #16a34a">
                <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Cobertura {{ $coverageYear }} · local elegido</span>
                <p class="text-xl font-semibold text-success-600 dark:text-success-400">{{ $resumen['coveragePercent'] }}%</p>
            </x-filament::section>
        </div>

        <x-filament::tabs contained>
            <x-filament::tabs.item tag="button" icon="heroicon-o-circle-stack" alpine-active="tab === 'nueva'" x-on:click="tab = 'nueva'">
                Nueva extracción
            </x-filament::tabs.item>
            <x-filament::tabs.item tag="button" icon="heroicon-o-calendar-days" alpine-active="tab === 'cobertura'" x-on:click="tab = 'cobertura'">
                Cobertura
            </x-filament::tabs.item>
            <x-filament::tabs.item tag="button" icon="heroicon-o-clock" alpine-active="tab === 'historial'" x-on:click="tab = 'historial'">
                Historial
                <x-slot:badge>{{ $resumen['corridas'] }}</x-slot:badge>
            </x-filament::tabs.item>
        </x-filament::tabs>

        <div x-show="tab === 'nueva'" class="space-y-4">
            <x-filament::section collapsible :collapsed="$this->extraccionActual() !== null" class="opm-query-section">
                <x-slot name="heading">Filtros de extracción</x-slot>

                <form wire:submit.prevent="iniciarExtraccion" class="space-y-4">
                    {{ $this->form }}

                    <x-filament::fieldset label="Fecha" class="opm-filter-date">
                        @include('filament.pages.ventas.partials.date-range-picker')
                    </x-filament::fieldset>

                    @if ($resultError)
                        <p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ $resultError }}</p>
                    @endif

                    <div class="opm-form-actions">
                        <x-filament::button
                            type="submit"
                            icon="heroicon-m-circle-stack"
                            :disabled="$this->hayExtraccionEnProgreso() || ! auth()->user()?->hasPermission('ventas.extraccion.iniciar')"
                        >
                            {{ $this->hayExtraccionEnProgreso() ? 'Ya hay una extracción en progreso…' : 'Iniciar extracción' }}
                        </x-filament::button>
                    </div>
                </form>
            </x-filament::section>

            @include('filament.pages.ventas.partials.extraccion-progreso')
        </div>

        <div x-show="tab === 'cobertura'" x-cloak>
            @include('filament.pages.ventas.partials.extraccion-cobertura')
        </div>

        <div x-show="tab === 'historial'" x-cloak>
            @include('filament.pages.ventas.partials.extraccion-historial')
        </div>
    </div>
</x-filament-panels::page>
