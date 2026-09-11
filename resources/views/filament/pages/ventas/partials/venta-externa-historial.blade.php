<div class="space-y-3">
    @forelse($venta->auditorias->sortByDesc('created_at') as $evento)
        <x-filament::section compact>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="font-medium text-gray-950 dark:text-white">{{ ucfirst($evento->accion) }}</span>
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $evento->created_at?->format('d/m/Y H:i:s') }} · {{ $evento->usuario?->name ?? 'Sistema' }}</span>
            </div>
            @if($evento->despues)
                <dl class="mt-3 grid gap-x-4 gap-y-2 text-sm sm:grid-cols-3">
                    <div><dt class="text-gray-500">Fecha</dt><dd>{{ $evento->despues['fecha'] ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Venta sin IGV</dt><dd>S/ {{ number_format((float) ($evento->despues['venta_sin_igv'] ?? 0), 2) }}</dd></div>
                    <div><dt class="text-gray-500">Venta con IGV</dt><dd>S/ {{ number_format((float) ($evento->despues['venta_con_igv'] ?? 0), 2) }}</dd></div>
                </dl>
            @endif
        </x-filament::section>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">No hay eventos registrados.</p>
    @endforelse
</div>
