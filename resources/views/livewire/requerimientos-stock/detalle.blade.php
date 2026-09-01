<div wire:init="sincronizar" wire:poll.60s="sincronizar">
    <div class="mb-3 flex items-center justify-between gap-3 text-sm text-gray-500 dark:text-gray-400">
        <span>
            @if ($sincronizando) Actualizando desde Restaurant…
            @elseif ($detalle) Copia local actualizada: {{ $detalle['cabecera']['sincronizado_en'] ?? 'ahora' }}
            @else Sin copia local disponible.
            @endif
        </span>
        @if ($sincronizando)<x-filament::loading-indicator class="h-4 w-4" />@endif
    </div>
    @if ($syncError)<p class="mb-3 text-sm text-warning-600 dark:text-warning-400">{{ $syncError }}</p>@endif
    @if ($detalle)
        @include('filament.pages.requerimientos-stock.detalle', ['detalle' => $detalle, 'historial' => $historial, 'historialRestaurant' => $historialRestaurant])
    @elseif (! $sincronizando)
        <p class="py-8 text-center text-sm text-gray-500">Sin datos para mostrar.</p>
    @endif
</div>
