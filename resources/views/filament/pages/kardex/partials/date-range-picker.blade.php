@php($displayRange = $this->dateRangeForDisplay())

<div wire:key="promedios-date-range-{{ $this->usesHistoricalCoverage() ? 'historical' : 'range' }}">
    @include('filament.components.date-range-picker', [
        'start' => $displayRange[0],
        'end' => $displayRange[1],
        'preset' => $activeDatePreset,
        'syncMethod' => 'syncDateRange',
        'disabled' => $this->usesHistoricalCoverage(),
    ])
</div>
