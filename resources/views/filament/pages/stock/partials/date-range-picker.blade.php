@include('filament.components.date-range-picker', [
    'start' => $data['fechaInicio'] ?? now()->toDateString(),
    'end' => $data['fechaFin'] ?? now()->toDateString(),
    'preset' => $activeDatePreset,
    'syncMethod' => 'syncDateRange',
])
