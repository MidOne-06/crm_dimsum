<x-filament-panels::page>
    <div class="space-y-4">
        {{ $this->form }}

        @if ($cabecera && $cabecera->estado === 'confirmado')
            <x-filament::section icon="heroicon-o-lock-closed" icon-color="warning">
                <p class="fi-in-text text-sm font-medium text-warning-600 dark:text-warning-400">
                    Este local ya confirmó su stock inicial el {{ $cabecera->confirmado_en?->format('d/m/Y H:i') }}.
                    Para corregir un valor, usa "Ajustar Stock" -- no se puede recargar ni sobreescribir esta carga.
                </p>
            </x-filament::section>
        @endif

        {{ $this->table }}
    </div>
</x-filament-panels::page>
