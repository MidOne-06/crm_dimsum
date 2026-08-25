<?php echo $__env->make('filament.components.date-range-picker', [
    'start' => $data['fechaInicio'] ?? now()->toDateString(),
    'end' => $data['fechaFin'] ?? now()->toDateString(),
    'preset' => $activeDatePreset,
    'syncMethod' => 'syncDateRange',
], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<?php /**PATH D:\DS-TI\CRM-DIMSUM\opm-digemid\resources\views/filament/pages/stock/partials/date-range-picker.blade.php ENDPATH**/ ?>