<div class="space-y-3">
    @forelse ($auditorias as $auditoria)
        <x-filament::section compact>
            <x-slot name="heading">{{ ucfirst($auditoria->accion) }}</x-slot>
            <x-slot name="description">{{ $auditoria->created_at?->format('d/m/Y H:i') }}{{ $auditoria->usuario ? ' · '.$auditoria->usuario->name : '' }}</x-slot>
            <div class="grid gap-3 text-sm md:grid-cols-2">
                <div><span class="text-gray-500 dark:text-gray-400">Sin IGV</span><div>S/ {{ number_format((float) data_get($auditoria->despues, 'cuota_sin_igv', 0), 2) }}</div></div>
                <div><span class="text-gray-500 dark:text-gray-400">Con IGV</span><div>S/ {{ number_format((float) data_get($auditoria->despues, 'cuota_con_igv', 0), 2) }}</div></div>
            </div>
        </x-filament::section>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">Sin cambios registrados.</p>
    @endforelse
</div>
