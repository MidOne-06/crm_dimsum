@include('filament.components.date-range-picker', [
    'start' => data_get($this->tableDeferredFilters ?? $this->tableFilters, 'criterios.fecha_inicio', now()->toDateString()),
    'end' => data_get($this->tableDeferredFilters ?? $this->tableFilters, 'criterios.fecha_fin', now()->toDateString()),
    'preset' => 'custom',
    'syncMethod' => 'syncRequirementDateRange',
])
