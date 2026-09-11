<x-filament-panels::page>
    <div class="space-y-4">
        <form>{{ $this->form }}</form>

        @php($periodos = $this->periodos())
        @if($periodos)
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div class="rounded-lg bg-primary-50 px-4 py-3 dark:bg-primary-500/10">
                    <p class="text-xs font-medium uppercase tracking-wide text-primary-700 dark:text-primary-300">Tramo 1</p>
                    <p class="mt-1 text-sm font-medium text-primary-900 dark:text-primary-100">
                        {{ $periodos['tramo1_inicio']->format('d/m/Y H:i') }} &rarr; {{ $periodos['tramo1_fin']->format('d/m/Y H:i') }}
                    </p>
                </div>
                <div class="rounded-lg bg-warning-50 px-4 py-3 dark:bg-warning-500/10">
                    <p class="text-xs font-medium uppercase tracking-wide text-warning-700 dark:text-warning-300">Tramo 2</p>
                    <p class="mt-1 text-sm font-medium text-warning-900 dark:text-warning-100">
                        {{ $periodos['tramo2_inicio']->format('d/m/Y H:i') }} &rarr; {{ $periodos['tramo2_fin']->format('d/m/Y H:i') }}
                    </p>
                </div>
            </div>

            @if($hace = $this->ultimoCalculoHace())
                <p class="text-xs text-gray-500 dark:text-gray-400">Última corrida calculada {{ $hace }}.</p>
            @endif
        @endif

        {{ $this->table }}
    </div>
</x-filament-panels::page>
