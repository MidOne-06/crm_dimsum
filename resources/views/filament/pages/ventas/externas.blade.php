<x-filament-panels::page>
    @php($resumen = $this->resumen())

    <div class="space-y-5">
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <x-filament::section compact>
                <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Ventas sin IGV</span>
                <p class="text-xl font-semibold text-primary-600 dark:text-primary-400">S/ {{ number_format($resumen['sin_igv'], 2) }}</p>
            </x-filament::section>
            <x-filament::section compact>
                <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Cuota mensual sin IGV</span>
                <p class="text-xl font-semibold text-gray-950 dark:text-white">S/ {{ number_format($resumen['cuota_sin_igv'], 2) }}</p>
            </x-filament::section>
            <x-filament::section compact>
                <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Avance</span>
                <p class="text-xl font-semibold {{ ($resumen['avance'] ?? 0) >= 100 ? 'text-success-600 dark:text-success-400' : 'text-warning-600 dark:text-warning-400' }}">{{ $resumen['avance'] === null ? '—' : number_format($resumen['avance'], 2).'%' }}</p>
            </x-filament::section>
            <x-filament::section compact>
                <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">TKP</span>
                <p class="text-xl font-semibold text-gray-950 dark:text-white">{{ $resumen['tkp'] === null ? '—' : 'S/ '.number_format($resumen['tkp'], 2) }}</p>
            </x-filament::section>
            <x-filament::section compact>
                <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">MB</span>
                <p class="text-xl font-semibold text-gray-950 dark:text-white">{{ $resumen['mb'] === null ? '—' : number_format($resumen['mb'], 2).'%' }}</p>
            </x-filament::section>
        </div>

        <x-filament::section compact>
            <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                <span class="font-medium text-gray-700 dark:text-gray-200">{{ \Carbon\Carbon::parse($desde)->format('d/m/Y') }} — {{ \Carbon\Carbon::parse($hasta)->format('d/m/Y') }}</span>
                <span class="text-gray-500 dark:text-gray-400">Fuente: registro manual externo</span>
            </div>
        </x-filament::section>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
