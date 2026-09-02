<div class="space-y-1.5">
    <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">Rango de fecha</span>

    @include('filament.components.date-range-picker', [
        'start' => data_get($this->tableDeferredFilters ?? $this->tableFilters, 'criterios.fecha_inicio', now()->toDateString()),
        'end' => data_get($this->tableDeferredFilters ?? $this->tableFilters, 'criterios.fecha_fin', now()->toDateString()),
        'preset' => 'custom',
        'syncMethod' => 'syncRequirementDateRange',
    ])
</div>
