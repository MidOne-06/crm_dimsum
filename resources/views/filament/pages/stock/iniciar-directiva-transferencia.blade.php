<x-filament-panels::page>
    <div class="space-y-4">
    @php($estado = $this->estadoActual())

    {{-- Foto del estado actual: para que cualquiera vea de un vistazo si conviene sincronizar de nuevo. --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-filament::section>
            <x-slot name="heading">Última corrida de la Directiva</x-slot>
            @if($estado['corrida']['existe'])
                <p class="text-2xl font-bold text-gray-950 dark:text-white">{{ $estado['corrida']['total'] }} <span class="text-sm font-normal text-gray-500">sugerencias</span></p>
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ $estado['corrida']['locales'] }} locales · despacho desde el {{ \Illuminate\Support\Carbon::parse($estado['corrida']['fecha'])->format('d/m/Y') }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-500">Calculada {{ $estado['corrida']['hace'] }}</p>
            @else
                <p class="text-sm text-gray-600 dark:text-gray-400">Todavía no se calculó ninguna Directiva.</p>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Kardex (saldo real)</x-slot>
            @if($estado['kardex']['estado'])
                <span class="fi-badge inline-flex items-center rounded-md bg-success-50 px-2 py-1 text-xs font-medium text-success-700 ring-1 ring-inset ring-success-600/20 dark:bg-success-400/10 dark:text-success-400">Sincronizado</span>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-500">Última vez {{ $estado['kardex']['hace'] }}</p>
            @else
                <span class="fi-badge inline-flex items-center rounded-md bg-gray-50 px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-500/10 dark:bg-gray-400/10 dark:text-gray-400">Sin datos aún</span>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Guías internas (en tránsito)</x-slot>
            @if($estado['guias']['estado'])
                <span class="fi-badge inline-flex items-center rounded-md bg-success-50 px-2 py-1 text-xs font-medium text-success-700 ring-1 ring-inset ring-success-600/20 dark:bg-success-400/10 dark:text-success-400">Sincronizado</span>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-500">Última vez {{ $estado['guias']['hace'] }}</p>
            @else
                <span class="fi-badge inline-flex items-center rounded-md bg-gray-50 px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-500/10 dark:bg-gray-400/10 dark:text-gray-400">Sin datos aún</span>
            @endif
        </x-filament::section>
    </div>

    @if($error)
        <div class="rounded-xl bg-danger-50 px-4 py-3 text-sm text-danger-700 ring-1 ring-inset ring-danger-600/20 dark:bg-danger-500/10 dark:text-danger-400">
            {{ $error }}
        </div>
    @endif

    @if($sincronizando)
        {{-- Progreso en curso: se actualiza sola cada 5s, no hace falta recargar. --}}
        <div wire:poll.5s="verificarSincronizacion">
            <x-filament::section>
                <x-slot name="heading">Sincronizando antes de calcular...</x-slot>
                <x-slot name="description">Esto puede tardar unos minutos. La pantalla avisa sola apenas termina.</x-slot>
                <div class="flex items-center gap-3 text-sm text-gray-700 dark:text-gray-300">
                    <svg class="h-5 w-5 animate-spin text-primary-600" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                    Actualizando Kardex (ayer y hoy) y Guías internas para todos los locales...
                </div>
            </x-filament::section>
        </div>
    @elseif($ultimoResultado)
        {{-- Resultado recién calculado, con el link directo para revisarlo. --}}
        <x-filament::section>
            <div class="flex items-start gap-3">
                <x-filament::icon icon="heroicon-o-check-circle" class="h-8 w-8 shrink-0 text-success-600" />
                <div>
                    <p class="text-base font-semibold text-gray-950 dark:text-white">Directiva calculada</p>
                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ $ultimoResultado['total'] }} sugerencias para {{ $ultimoResultado['locales'] }} locales · despacho desde el {{ \Illuminate\Support\Carbon::parse($ultimoResultado['fecha'])->format('d/m/Y') }}.</p>
                    <div class="mt-3 flex gap-2">
                        <x-filament::button tag="a" href="{{ \App\Filament\Pages\Stock\DirectivaTransferenciaConsolidado::getUrl() }}" icon="heroicon-o-table-cells">Ver la Directiva</x-filament::button>
                        <x-filament::button color="gray" wire:click="$set('ultimoResultado', null)">Iniciar otra corrida</x-filament::button>
                    </div>
                </div>
            </div>
        </x-filament::section>
    @else
        {{-- El formulario: alcance + ajustes opcionales, siempre visible. --}}
        <form wire:submit="sincronizarYCalcular">
            {{ $this->form }}

            <div class="mt-4 flex flex-wrap items-center gap-3">
                <x-filament::button type="submit" icon="heroicon-o-bolt" size="lg">
                    Sincronizar y Calcular la Directiva de Mañana
                </x-filament::button>
                <x-filament::button type="button" color="gray" wire:click="calcularSinSincronizar" icon="heroicon-o-arrow-path">
                    Calcular sin sincronizar (usar datos actuales)
                </x-filament::button>
            </div>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-500">{{ $this->conteoAlcanceEnVivo() }}</p>
        </form>
    @endif

    @php($historial = $this->historial())
    @if($historial->isNotEmpty())
        <x-filament::section>
            <x-slot name="heading">Corridas recientes</x-slot>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="text-xs uppercase text-gray-500 dark:text-gray-500">
                            <th class="py-1 pr-4">Cuándo</th>
                            <th class="py-1 pr-4">Sugerencias</th>
                            <th class="py-1 pr-4">Locales</th>
                            <th class="py-1 pr-4">Despacho desde</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($historial as $fila)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="py-1.5 pr-4 text-gray-700 dark:text-gray-300">{{ $fila['calculado_en'] }} <span class="text-xs text-gray-400">({{ $fila['hace'] }})</span></td>
                                <td class="py-1.5 pr-4 text-gray-700 dark:text-gray-300">{{ $fila['total'] }}</td>
                                <td class="py-1.5 pr-4 text-gray-700 dark:text-gray-300">{{ $fila['locales'] }}</td>
                                <td class="py-1.5 pr-4 text-gray-700 dark:text-gray-300">{{ $fila['fecha'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
    </div>
</x-filament-panels::page>
