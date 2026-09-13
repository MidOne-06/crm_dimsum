@php
    $archivo = $get('archivo');
    $periodo = $get('periodo');
    $resumen = null;
    $errorArchivo = null;

    if (filled($archivo) && filled($periodo)) {
        try {
            $ruta = \Illuminate\Support\Facades\Storage::disk('local')->path((string) $archivo);
            if (is_file($ruta)) {
                $resumen = app(\App\Services\CuotasVentasRestaurantService::class)->prevalidarExcel(
                    $ruta,
                    $periodo,
                    (bool) $get('sobrescribir'),
                );
            }
        } catch (\Throwable $exception) {
            $errorArchivo = 'No se pudo leer el archivo seleccionado.';
        }
    }
@endphp

<x-filament::section compact heading="Validación">
    @if ($resumen === null)
        <x-filament::badge color="gray">Selecciona el mes y el archivo.</x-filament::badge>
    @else
        <div class="flex flex-wrap gap-2">
            <x-filament::badge color="gray">{{ $resumen['total'] }} filas válidas</x-filament::badge>
            <x-filament::badge color="success">{{ $resumen['nuevas'] }} nuevas</x-filament::badge>
            @if ($resumen['actualizaciones'] > 0)
                <x-filament::badge color="warning">{{ $resumen['actualizaciones'] }} existentes</x-filament::badge>
            @endif
            @if ($resumen['errores'] !== [])
                <x-filament::badge color="danger">{{ count($resumen['errores']) }} observaciones</x-filament::badge>
            @endif
        </div>

        @if ($resumen['errores'] !== [])
            <ul class="mt-3 list-disc space-y-1 ps-5 text-sm text-danger-600 dark:text-danger-400">
                @foreach (array_slice($resumen['errores'], 0, 6) as $error)
                    <li>{{ $error }}</li>
                @endforeach
                @if (count($resumen['errores']) > 6)
                    <li>Y {{ count($resumen['errores']) - 6 }} observaciones más.</li>
                @endif
            </ul>
        @endif
    @endif

    @if ($errorArchivo)
        <p class="mt-3 text-sm text-danger-600 dark:text-danger-400">{{ $errorArchivo }}</p>
    @endif
</x-filament::section>
