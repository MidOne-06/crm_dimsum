@php($resumen = $this->resumenGeneral())
@php($extraccion = $this->extraccionActual())

<x-filament-panels::page>
    <div class="space-y-4" x-data="{ tab: 'nueva' }">
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
            <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color:#64748b"><span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Guías guardadas</span><p class="text-xl font-semibold text-gray-950 dark:text-white">{{ number_format($resumen['guias']) }}</p></x-filament::section>
            <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color:#3e86d8"><span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Detalles guardados</span><p class="text-xl font-semibold text-gray-950 dark:text-white">{{ number_format($resumen['detalles']) }}</p></x-filament::section>
            <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color:#3e86d8"><span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Corridas totales</span><p class="text-xl font-semibold text-gray-950 dark:text-white">{{ number_format($resumen['corridas']) }}</p></x-filament::section>
            <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color:#dc2626"><span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Corridas fallidas</span><p class="text-xl font-semibold text-danger-600 dark:text-danger-400">{{ number_format($resumen['fallidas']) }}</p></x-filament::section>
            <x-filament::section compact class="crm-kpi-card" style="--crm-kpi-color:#16a34a"><span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Cobertura {{ $coverageYear }}</span><p class="text-xl font-semibold text-success-600 dark:text-success-400">{{ $resumen['coveragePercent'] }}%</p></x-filament::section>
        </div>

        <x-filament::tabs contained>
            <x-filament::tabs.item tag="button" icon="heroicon-o-circle-stack" alpine-active="tab === 'nueva'" x-on:click="tab = 'nueva'">Nueva extracción</x-filament::tabs.item>
            <x-filament::tabs.item tag="button" icon="heroicon-o-calendar-days" alpine-active="tab === 'cobertura'" x-on:click="tab = 'cobertura'">Cobertura</x-filament::tabs.item>
            <x-filament::tabs.item tag="button" icon="heroicon-o-clock" alpine-active="tab === 'historial'" x-on:click="tab = 'historial'">Historial <x-slot:badge>{{ $resumen['corridas'] }}</x-slot:badge></x-filament::tabs.item>
        </x-filament::tabs>

        <div x-show="tab === 'nueva'" class="space-y-4">
            <x-filament::section collapsible :collapsed="$extraccion !== null" class="crm-query-section">
                <x-slot name="heading">Filtros de extracción</x-slot>
                <form wire:submit.prevent="iniciarExtraccion" class="space-y-4">
                    {{ $this->form }}
                    <x-filament::fieldset label="Fecha" class="crm-filter-date">
                        @include('filament.components.date-range-picker',['start'=>$data['dateStart'] ?? now()->toDateString(),'end'=>$data['dateEnd'] ?? now()->toDateString(),'preset'=>$activeDatePreset,'syncMethod'=>'syncDateRange'])
                    </x-filament::fieldset>
                    @if($resultError)<p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ $resultError }}</p>@endif
                    <div class="crm-form-actions"><x-filament::button type="submit" icon="heroicon-m-circle-stack" :disabled="$this->hayExtraccionEnProgreso()">{{ $this->hayExtraccionEnProgreso() ? 'Ya hay una extracción en progreso…' : 'Iniciar extracción' }}</x-filament::button></div>
                </form>
            </x-filament::section>

            @if($extraccion || $esperandoExtraccion)
                <div @if($esperandoExtraccion || ($extraccion && in_array($extraccion->estado,['pendiente','en_progreso'],true))) wire:poll.3s="refreshExtraccion" @endif>
                    @if($extraccion)
                    <x-filament::section>
                        <x-slot name="heading">Extracción #{{ $extraccion->id }} <span class="crm-status">{{ ucfirst(str_replace('_',' ',$extraccion->estado)) }}</span></x-slot>
                        @php($progreso = $extraccion->paginas_total > 0 ? min(100, round(($extraccion->paginas_procesadas / $extraccion->paginas_total) * 100)) : 0)
                        <div class="mb-3"><div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10"><div class="h-full rounded-full bg-primary-600 transition-all" style="width:{{ $progreso }}%"></div></div><p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $progreso }}% · {{ $extraccion->paginas_procesadas }} de {{ $extraccion->paginas_total ?: '—' }} páginas procesadas</p></div>
                        <div class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4"><div><span class="block text-gray-500 dark:text-gray-400">Cabeceras</span><strong class="text-gray-950 dark:text-white">{{ $extraccion->cabeceras_guardadas }}</strong></div><div><span class="block text-gray-500 dark:text-gray-400">Detalles</span><strong class="text-gray-950 dark:text-white">{{ $extraccion->detalles_guardados }}</strong></div><div><span class="block text-gray-500 dark:text-gray-400">Eliminadas</span><strong class="text-gray-950 dark:text-white">{{ $extraccion->cabeceras_eliminadas }}</strong></div><div><span class="block text-gray-500 dark:text-gray-400">Fallidas</span><strong class="text-danger-600 dark:text-danger-400">{{ $extraccion->errores }}</strong></div></div>
                        @if(in_array($extraccion->estado,['fallido','completado_con_errores'],true) && $extraccion->mensaje_error)<p class="mt-3 text-sm font-medium text-danger-600 dark:text-danger-400">{{ $extraccion->mensaje_error }}</p>@endif
                    </x-filament::section>
                    @else
                    <x-filament::section><x-slot name="heading">Preparando extracción</x-slot><p class="text-sm text-gray-500 dark:text-gray-400">Conectando con Restaurant…</p></x-filament::section>
                    @endif
                </div>
            @endif
        </div>

        <div x-show="tab === 'cobertura'" x-cloak>@include('filament.pages.stock.partials.extraccion-guias-cobertura')</div>
        <div x-show="tab === 'historial'" x-cloak><x-filament::section><x-slot name="heading">Historial de extracciones</x-slot>{{ $this->table }}</x-filament::section></div>
    </div>
</x-filament-panels::page>
