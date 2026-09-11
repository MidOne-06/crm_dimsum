<x-filament-panels::page>
    <div class="space-y-3">

    @if($error)
        <div class="rounded-lg bg-danger-50 px-4 py-2.5 text-sm text-danger-700 ring-1 ring-inset ring-danger-600/20 dark:bg-danger-500/10 dark:text-danger-400">
            {{ $error }}
        </div>
    @endif

    @if($sincronizando)
        @php($minutos = $this->minutosEsperando())
        <div wire:poll.5s="verificarSincronizacion" class="flex items-center gap-3 rounded-lg bg-primary-50 px-4 py-3 text-sm text-primary-700 dark:bg-primary-500/10 dark:text-primary-300">
            <svg class="h-5 w-5 shrink-0 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
            Generando la Directiva...{{ $minutos > 0 ? " ({$minutos} min)" : '' }}
        </div>
        @if($minutos >= 3)
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-warning-50 px-4 py-2.5 text-sm text-warning-700 dark:bg-warning-500/10 dark:text-warning-300">
                <span>Se está demorando más de lo normal. Puede que Kardex o Guías internas esté trabado -- revisá esas pantallas, o cancelá la espera acá.</span>
                <div class="flex shrink-0 gap-2">
                    <x-filament::link tag="a" href="{{ \App\Filament\Pages\Kardex\KardexExtraccion::getUrl() }}" target="_blank" size="sm">Ver Kardex</x-filament::link>
                    <x-filament::link tag="a" href="{{ \App\Filament\Pages\Stock\ExtraccionGuiasInternas::getUrl() }}" target="_blank" size="sm">Ver Guías internas</x-filament::link>
                    <x-filament::button wire:click="cancelarEspera" size="sm" color="danger">Cancelar espera</x-filament::button>
                </div>
            </div>
        @endif
    @elseif($ultimoResultado)
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-success-50 px-4 py-3 dark:bg-success-500/10">
            <span class="flex items-center gap-2 text-sm font-medium text-success-800 dark:text-success-300">
                <x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5" />
                {{ $ultimoResultado['total'] }} sugerencias · {{ $ultimoResultado['locales'] }} locales · despacho {{ \Illuminate\Support\Carbon::parse($ultimoResultado['fecha'])->format('d/m/Y') }}
            </span>
            <div class="flex gap-2">
                <x-filament::button tag="a" href="{{ \App\Filament\Pages\Stock\DirectivaTransferenciaConsolidado::getUrl() }}" size="sm" icon="heroicon-o-table-cells">Ver Directiva</x-filament::button>
                <x-filament::button size="sm" color="gray" wire:click="$set('ultimoResultado', null)">Nueva corrida</x-filament::button>
            </div>
        </div>
    @else
        <form wire:submit="generarDt" class="space-y-3">
            {{ $this->form }}

            <div class="flex items-center gap-4">
                <x-filament::button type="submit" icon="heroicon-o-bolt" size="lg">Generar DT</x-filament::button>
                <x-filament::link tag="a" href="{{ \App\Filament\Pages\Stock\LocalesActivos::getUrl() }}" target="_blank" icon="heroicon-o-signal" size="sm">Ver locales activos</x-filament::link>
            </div>
        </form>
    @endif

    <x-filament::modal id="exportar-directiva" width="sm" icon="heroicon-o-check-circle" icon-color="success">
        <x-slot name="heading">Directiva generada</x-slot>
        <x-slot name="description">
            @if($ultimoResultado)
                {{ $ultimoResultado['total'] }} sugerencias · {{ $ultimoResultado['locales'] }} locales · despacho {{ \Illuminate\Support\Carbon::parse($ultimoResultado['fecha'])->format('d/m/Y') }}. ¿Cómo querés exportarla?
            @endif
        </x-slot>
        <x-slot name="footerActions">
            <x-filament::button wire:click="exportarPdf" icon="heroicon-o-document-arrow-down">Exportar PDF</x-filament::button>
            <x-filament::button wire:click="exportarExcel" color="gray" icon="heroicon-o-arrow-down-tray">Exportar Excel</x-filament::button>
        </x-slot>
    </x-filament::modal>

    @php($historial = $this->historial())
    @if($historial->isNotEmpty())
        <details class="rounded-lg bg-gray-50 px-4 py-2 text-sm dark:bg-white/5">
            <summary class="cursor-pointer font-medium text-gray-700 dark:text-gray-300">Historial de cálculos</summary>
            <div class="mt-2 overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <tbody>
                        @foreach($historial as $fila)
                            <tr class="border-t border-gray-200 dark:border-white/10">
                                <td class="py-1 pr-4 text-gray-500">{{ $fila['calculado_en'] }}</td>
                                <td class="py-1 pr-4 text-gray-700 dark:text-gray-300">{{ $fila['total'] }} sug.</td>
                                <td class="py-1 pr-4 text-gray-700 dark:text-gray-300">{{ $fila['locales'] }} locales</td>
                                <td class="py-1 text-gray-700 dark:text-gray-300">despacho {{ $fila['fecha'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @endif

    </div>
</x-filament-panels::page>
