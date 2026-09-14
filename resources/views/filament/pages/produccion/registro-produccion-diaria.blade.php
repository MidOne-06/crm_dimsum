<x-filament-panels::page>
    <form class="space-y-4">
        {{ $this->form }}

        @if (empty($data['items'] ?? []))
            <x-filament::section>
                <p class="text-sm text-gray-600 dark:text-gray-300">No hay productos con cantidad sugerida en la DT de esta fecha.</p>
            </x-filament::section>
        @endif

        <div class="flex flex-wrap justify-end gap-3">
            @if ($this->puedeRegistrar() && ! $this->soloLectura())
                <x-filament::button type="button" color="gray" wire:click="guardarBorrador" wire:loading.attr="disabled" wire:target="guardarBorrador">Guardar borrador</x-filament::button>
                <x-filament::button type="button" color="primary" wire:click="enviarCierre" wire:loading.attr="disabled" wire:target="enviarCierre">Enviar cierre</x-filament::button>
            @endif
            @if ($this->puedeAprobar() && $estado === 'enviado')
                <x-filament::button type="button" color="success" wire:click="aprobar" wire:confirm="Se aprobará el cierre y quedará bloqueado. Kardex no será modificado. ¿Continuar?" wire:loading.attr="disabled" wire:target="aprobar">Aprobar cierre</x-filament::button>
            @endif
        </div>
    </form>
</x-filament-panels::page>
