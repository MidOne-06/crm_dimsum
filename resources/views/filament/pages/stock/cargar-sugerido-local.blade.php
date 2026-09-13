<x-filament-panels::page>
    <div class="space-y-4">
        <form>{{ $this->form }}</form>

        @if($fecha = $this->fechaDespacho())
            <p class="text-sm text-gray-600 dark:text-gray-400">Directiva calculada para el despacho del <strong>{{ \Illuminate\Support\Carbon::parse($fecha)->format('d/m/Y') }}</strong>.</p>
        @endif

        {{ $this->table }}
    </div>
</x-filament-panels::page>
