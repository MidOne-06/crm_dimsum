<div class="space-y-3">
    @forelse($eventos as $evento)
        <x-filament::section compact>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="font-medium text-gray-950 dark:text-white">{{ ucfirst($evento->accion) }}</span>
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $evento->created_at?->format('d/m/Y H:i:s') }} · {{ $evento->usuario?->name ?? 'Sistema' }}</span>
            </div>
            <dl class="mt-3 grid gap-x-4 gap-y-2 text-sm sm:grid-cols-2">
                @if($evento->multiplos_solicitados !== null)
                    <div><dt class="text-gray-500">Ajuste pedido</dt><dd>{{ $evento->multiplos_solicitados > 0 ? '+' : '' }}{{ $evento->multiplos_solicitados }} múltiplo(s) = {{ $evento->delta_unidades > 0 ? '+' : '' }}{{ number_format((float) $evento->delta_unidades, 0) }} unidades</dd></div>
                @endif
                @if($evento->motivo)
                    <div><dt class="text-gray-500">Motivo</dt><dd>{{ $evento->motivo }}</dd></div>
                @endif
                @if($evento->comentario_admin)
                    <div class="sm:col-span-2"><dt class="text-gray-500">Comentario del admin</dt><dd>{{ $evento->comentario_admin }}</dd></div>
                @endif
            </dl>
        </x-filament::section>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">No hay historial para este producto todavía.</p>
    @endforelse
</div>
