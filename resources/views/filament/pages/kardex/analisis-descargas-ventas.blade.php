<x-filament-panels::page>
    @php($analysis = $this->analysis())

    <div class="space-y-6">
        <x-filament::section collapsible class="crm-query-section">
            <x-slot name="heading">Ventas por producto, local y fecha</x-slot>

            <form wire:submit="search" class="space-y-4">
                {{ $this->form }}

                <x-filament::fieldset label="Rango de fecha" class="crm-filter-date">
                    @include('filament.pages.kardex.partials.date-range-picker')
                </x-filament::fieldset>

                <div class="crm-form-actions">
                    <p class="crm-query-hint">SALIDA, POR VENTA. · Almacén Principal · {{ $analysis['unidad'] ?: 'Unidad seleccionada' }}</p>
                    <x-filament::button type="submit" icon="heroicon-m-chart-bar" wire:loading.attr="disabled" wire:target="search">
                        Actualizar
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color: #d97706">
                <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Ventas</span>
                <p class="text-xl font-semibold text-warning-600 dark:text-warning-400">{{ number_format($analysis['descargas'] ?? 0, 0) }}</p>
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $analysis['unidad'] ?: 'Sin unidad' }}</span>
            </x-filament::section>
            <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color: #64748b">
                <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Movimientos</span>
                <p class="text-xl font-semibold text-gray-950 dark:text-white">{{ number_format($analysis['movimientos'] ?? 0) }}</p>
                <span class="text-xs text-gray-500 dark:text-gray-400">líneas de kardex</span>
            </x-filament::section>
            <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color: #3b82f6">
                <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Locales activos</span>
                <p class="text-xl font-semibold text-primary-600 dark:text-primary-400">{{ number_format($analysis['locales'] ?? 0) }}</p>
                <span class="text-xs text-gray-500 dark:text-gray-400">con ventas</span>
            </x-filament::section>
            <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color: #16a34a">
                <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Cobertura</span>
                <p class="text-xl font-semibold text-success-600 dark:text-success-400">{{ number_format($analysis['cobertura'] ?? 0, 1) }}%</p>
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $analysis['dias_con_datos'] ?? 0 }} de {{ $analysis['dias_periodo'] ?? 0 }} días</span>
            </x-filament::section>
            <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color: #8b5cf6">
                <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Promedio diario</span>
                <p class="text-xl font-semibold text-violet-600 dark:text-violet-400">{{ number_format($analysis['promedio_diario'] ?? 0, 0) }}</p>
                <span class="text-xs text-gray-500 dark:text-gray-400">por día con datos</span>
            </x-filament::section>
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
            @livewire(\App\Filament\Widgets\Kardex\DescargasVentasTendenciaChart::class, ['analysisFilters' => $analysis['filters'] ?? []], key('kardex-descargas-tendencia-'.md5(json_encode($analysis['filters'] ?? []))))
            @livewire(\App\Filament\Widgets\Kardex\DescargasVentasLocalesChart::class, ['analysisFilters' => $analysis['filters'] ?? []], key('kardex-descargas-locales-'.md5(json_encode($analysis['filters'] ?? []))))
        </div>

        <x-filament::section compact class="crm-query-section">
            <x-slot name="heading">Comparación del gráfico</x-slot>

            <div class="space-y-3">
                {{ $this->comparison }}

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex flex-wrap gap-2">
                        <x-filament::button size="sm" color="gray" wire:click="applyDailyPreset('today')">Último día cargado</x-filament::button>
                        <x-filament::button size="sm" color="gray" wire:click="applyDailyPreset('yesterday')">Día anterior</x-filament::button>
                        <x-filament::button size="sm" color="gray" wire:click="applyDailyPreset('before_yesterday')">Dos días antes</x-filament::button>
                        <x-filament::button size="sm" color="gray" wire:click="applyDailyPreset('last7')">Últimos 7 días</x-filament::button>
                    </div>
                    <x-filament::button wire:click="search" icon="heroicon-m-arrow-path" wire:loading.attr="disabled" wire:target="search">
                        Actualizar gráfico
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>

        @livewire(\App\Filament\Widgets\Kardex\DescargasVentasProductosLocalesChart::class, ['analysisFilters' => $analysis['comparisonFilters'] ?? []], key('kardex-descargas-productos-locales-'.md5(json_encode($analysis['comparisonFilters'] ?? []))))

        {{ $this->table }}
    </div>
</x-filament-panels::page>
