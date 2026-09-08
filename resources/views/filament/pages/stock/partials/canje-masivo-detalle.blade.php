@php
    $resultado = (array) ($canje->resultado ?? []);
    $excluidas = (array) ($resultado['excluidas'] ?? []);
    $fallosPreview = (array) ($resultado['fallos_preview'] ?? []);
    $movimientosCreados = (array) ($resultado['movimientos_creados'] ?? []);
    $fallos = (array) ($resultado['fallos'] ?? []);
@endphp

<div class="space-y-6">
    @if (filled($canje->mensaje_error))
        <div class="rounded-xl border border-danger-300 bg-danger-50 p-4 text-sm text-danger-700 dark:border-danger-500/30 dark:bg-danger-500/10 dark:text-danger-400">
            {{ $canje->mensaje_error }}
        </div>
    @endif

    <section class="space-y-3">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Movimientos registrados ({{ count($movimientosCreados) }})</h3>
        @forelse ($movimientosCreados as $mov)
            <div class="rounded-xl border border-gray-200 p-3 text-sm dark:border-white/10">
                <span class="font-medium text-gray-950 dark:text-white">Movimiento #{{ $mov['movimiento_id'] ?? '—' }}</span>
                <span class="text-gray-500 dark:text-gray-400"> · Guías #{{ implode(', #', (array) ($mov['guias'] ?? [])) }}</span>
            </div>
        @empty
            <p class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-white/20">Todavía no se registró ningún movimiento.</p>
        @endforelse
    </section>

    @if ($fallos !== [])
        <section class="space-y-3">
            <h3 class="text-base font-semibold text-danger-600 dark:text-danger-400">Fallos al confirmar ({{ count($fallos) }})</h3>
            @foreach ($fallos as $fallo)
                <div class="rounded-xl border border-danger-300 bg-danger-50 p-3 text-sm dark:border-danger-500/30 dark:bg-danger-500/10">
                    <span class="font-medium text-danger-700 dark:text-danger-400">Guías #{{ implode(', #', (array) ($fallo['guias'] ?? [])) }}</span>
                    <span class="text-danger-600 dark:text-danger-400"> — {{ $fallo['error'] ?? 'Error desconocido.' }}</span>
                </div>
            @endforeach
        </section>
    @endif

    @if ($fallosPreview !== [])
        <section class="space-y-3">
            <h3 class="text-base font-semibold text-warning-600 dark:text-warning-400">Lotes que fallarían al confirmar ({{ count($fallosPreview) }})</h3>
            @foreach ($fallosPreview as $fallo)
                <div class="rounded-xl border border-warning-300 bg-warning-50 p-3 text-sm dark:border-warning-500/30 dark:bg-warning-500/10">
                    <span class="font-medium text-warning-700 dark:text-warning-400">Guías #{{ implode(', #', (array) ($fallo['ids'] ?? [])) }}</span>
                    <span class="text-warning-600 dark:text-warning-400"> — {{ $fallo['error'] ?? 'Error desconocido.' }}</span>
                </div>
            @endforeach
        </section>
    @endif

    <section class="space-y-3">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Excluidas del filtro ({{ count($excluidas) }})</h3>
        @forelse ($excluidas as $exclusion)
            <div class="rounded-xl border border-gray-200 p-3 text-sm dark:border-white/10">
                <span class="font-medium text-gray-950 dark:text-white">Guía #{{ $exclusion['id'] ?? '—' }}</span>
                <span class="text-gray-500 dark:text-gray-400"> — {{ $exclusion['motivo'] ?? '' }}</span>
            </div>
        @empty
            <p class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-white/20">Ninguna guía fue excluida del filtro.</p>
        @endforelse
    </section>
</div>
