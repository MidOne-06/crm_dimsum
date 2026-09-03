@if (! $detail)
    <x-filament::empty-state icon="heroicon-o-exclamation-triangle" heading="El detalle aún no está disponible en la copia local." />
@else
    <div class="crm-detail-summary">
        <div><span>Responsable</span><strong>{{ $detail['registradoPor'] ?: '—' }}</strong></div>
        <div><span>Local</span><strong>{{ $detail['local'] ?? '—' }}</strong></div>
        <div><span>Registro</span><strong>{{ $detail['fechaRegistro'] ?? '—' }}</strong></div>
        <div><span>Cuadre</span><strong>{{ $detail['fechaCuadre'] ?? '—' }}</strong></div>
        <div><span>Ítems</span><strong>{{ count($detail['items'] ?? []) }}</strong></div>
    </div>

    @if (count($detail['items'] ?? []))
        <livewire:requerimientos-stock.tabla
            :rows="$rows"
            :columns="$columns"
            wire:key="stock-detail-{{ $detail['id'] ?? 'actual' }}"
        />
    @else
        <x-filament::empty-state icon="heroicon-o-inbox" heading="Este cuadre no tiene ítems." />
    @endif
@endif
