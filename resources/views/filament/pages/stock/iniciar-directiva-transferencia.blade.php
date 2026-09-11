<x-filament-panels::page>
    @php($estado = $this->estadoActual())
    <div class="space-y-3">

    {{-- Estado en una sola línea, sin texto de más. --}}
    <div class="flex flex-wrap items-center gap-x-6 gap-y-1 rounded-lg bg-gray-50 px-4 py-2.5 text-sm dark:bg-white/5">
        <span class="font-medium text-gray-950 dark:text-white">
            @if($estado['corrida']['existe'])
                {{ $estado['corrida']['total'] }} sugerencias · {{ $estado['corrida']['locales'] }} locales · {{ \Illuminate\Support\Carbon::parse($estado['corrida']['fecha'])->format('d/m') }} <span class="font-normal text-gray-500">({{ $estado['corrida']['hace'] }})</span>
            @else
                Sin calcular todavía
            @endif
        </span>
        <span class="flex items-center gap-1.5 text-gray-600 dark:text-gray-400">
            <span class="h-2 w-2 rounded-full {{ $estado['kardex']['estado'] ? 'bg-success-500' : 'bg-gray-400' }}"></span>
            Kardex {{ $estado['kardex']['hace'] ?? 'sin datos' }}
        </span>
        <span class="flex items-center gap-1.5 text-gray-600 dark:text-gray-400">
            <span class="h-2 w-2 rounded-full {{ $estado['guias']['estado'] ? 'bg-success-500' : 'bg-gray-400' }}"></span>
            Guías {{ $estado['guias']['hace'] ?? 'sin datos' }}
        </span>
    </div>

    @if($error)
        <div class="rounded-lg bg-danger-50 px-4 py-2.5 text-sm text-danger-700 ring-1 ring-inset ring-danger-600/20 dark:bg-danger-500/10 dark:text-danger-400">
            {{ $error }}
        </div>
    @endif

    @if($sincronizando)
        <div wire:poll.5s="verificarSincronizacion" class="flex items-center gap-3 rounded-lg bg-primary-50 px-4 py-3 text-sm text-primary-700 dark:bg-primary-500/10 dark:text-primary-300">
            <svg class="h-5 w-5 shrink-0 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
            Sincronizando Kardex y Guías...
        </div>
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
        <form wire:submit="sincronizarYCalcular" class="space-y-3">
            {{ $this->form }}

            <div class="flex flex-wrap items-center gap-2">
                <x-filament::button type="submit" icon="heroicon-o-bolt">Sincronizar y Calcular</x-filament::button>
                <x-filament::button type="button" color="gray" wire:click="calcularSinSincronizar" icon="heroicon-o-arrow-path">Calcular sin sincronizar</x-filament::button>
                <span class="text-xs text-gray-500 dark:text-gray-500">{{ $this->conteoAlcanceEnVivo() }}</span>
            </div>
        </form>
    @endif

    @php($historial = $this->historial())
    @if($historial->isNotEmpty())
        <details class="rounded-lg bg-gray-50 px-4 py-2 text-sm dark:bg-white/5">
            <summary class="cursor-pointer font-medium text-gray-700 dark:text-gray-300">Corridas recientes</summary>
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
