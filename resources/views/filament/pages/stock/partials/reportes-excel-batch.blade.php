<div class="space-y-6">
    <p class="text-sm text-gray-600 dark:text-gray-400">
        Restaurant prepara estos archivos en segundo plano. Los procesos terminados se descargan desde el enlace correspondiente.
    </p>

    <section class="space-y-3">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">En proceso</h3>
        @forelse ($pendientes as $reporte)
            <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <p class="font-medium text-gray-950 dark:text-white">{{ $reporte['proceso_estadotxt'] ?? 'Procesando reporte' }}</p>
                        <p class="mt-1 text-sm text-gray-500">Proceso #{{ $reporte['proceso_id'] ?? '—' }} · {{ $reporte['proceso_fecharegistrotxt'] ?? 'Sin fecha' }}</p>
                    </div>
                    <x-filament::badge color="warning">En proceso</x-filament::badge>
                </div>
            </div>
        @empty
            <p class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-white/20">No hay reportes de guías internas pendientes.</p>
        @endforelse
    </section>

    <section class="space-y-3">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Terminados</h3>
        @forelse ($terminados as $reporte)
            <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <p class="font-medium text-gray-950 dark:text-white">{{ $reporte['proceso_estadotxt'] ?? 'Reporte terminado' }}</p>
                        <p class="mt-1 text-sm text-gray-500">Proceso #{{ $reporte['proceso_id'] ?? '—' }} · {{ $reporte['proceso_fechaterminotxt'] ?? ($reporte['proceso_fecharegistrotxt'] ?? 'Sin fecha') }}</p>
                    </div>
                    @if (filled($reporte['proceso_ruta'] ?? null))
                        <x-filament::button tag="a" :href="$reporte['proceso_ruta']" target="_blank" icon="heroicon-o-arrow-down-tray" size="sm">
                            Descargar
                        </x-filament::button>
                    @else
                        <x-filament::badge color="gray">Sin archivo</x-filament::badge>
                    @endif
                </div>
            </div>
        @empty
            <p class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-white/20">Aún no hay reportes de guías internas terminados.</p>
        @endforelse
    </section>
</div>
