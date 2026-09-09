<x-filament-panels::page>
    @if($sincronizandoParaManana)
        <div wire:poll.5s="verificarSincronizacionParaManana" class="fi-in-text rounded-xl bg-primary-50 px-4 py-3 text-sm text-primary-700 dark:bg-primary-500/10 dark:text-primary-300">
            Sincronizando Kardex de hoy y Guías internas antes de calcular la Directiva de mañana -- esta pantalla se actualiza sola, no hace falta recargar.
        </div>
    @endif
    {{ $this->table }}
</x-filament-panels::page>
