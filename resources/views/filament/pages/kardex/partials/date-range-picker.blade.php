@include('filament.components.date-range-picker', [
    'start' => $data['dateStart'] ?? now()->toDateString(),
    'end' => $data['dateEnd'] ?? now()->toDateString(),
    'preset' => $activeDatePreset,
    'syncMethod' => 'syncDateRange',
])
