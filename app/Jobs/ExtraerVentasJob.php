<?php

namespace App\Jobs;

use App\Models\VentaExtraccion;
use App\Models\VentaExtraccionPagina;
use App\Services\SalesGatewayClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Throwable;

class ExtraerVentasJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 300;

    public array $backoff = [30, 120, 300];

    public function __construct(public int $extraccionId)
    {
    }

    public function handle(SalesGatewayClient $gateway): void
    {
        $extraccion = VentaExtraccion::find($this->extraccionId);

        if (! $extraccion || ! in_array($extraccion->estado, ['pendiente', 'planificando', 'en_progreso'], true)) {
            return;
        }

        if ($extraccion->estado === 'en_progreso') {
            VentaExtraccionPagina::query()
                ->where('extraccion_id', $extraccion->id)
                ->where('estado', 'pendiente')
                ->orderBy('pagina')
                ->pluck('id')
                ->each(fn (int $pageId) => ProcesarPaginaVentasJob::dispatch($extraccion->id, $pageId)->onQueue('ventas-pages'));

            return;
        }

        $extraccion->update(['estado' => 'planificando', 'iniciado_at' => $extraccion->iniciado_at ?? now()]);

        $filters = $extraccion->filtros ?? [];
        $end = Carbon::parse($filters['fechaFin']);
        $total = 0;
        $jobs = [];

        for ($start = Carbon::parse($filters['fechaInicio']); $start->lte($end); $start->addDays(33)) {
            $finish = $start->copy()->addDays(32)->min($end);
            $firstPage = $gateway->sales([...$filters, 'fechaInicio' => $start->toDateString(), 'fechaFin' => $finish->toDateString(), 'pagina' => 1, 'registros' => 200]);
            $pages = max(1, (int) ($firstPage['paginas'] ?? 1));
            $total += (int) ($firstPage['total'] ?? count($firstPage['rows'] ?? []));

            foreach (range(1, $pages) as $page) {
                $jobs[] = ['extraccion_id' => $extraccion->id, 'pagina' => $page, 'fecha_inicio' => $start->toDateString(), 'fecha_fin' => $finish->toDateString(), 'estado' => 'pendiente', 'created_at' => now(), 'updated_at' => now()];
            }
        }

        DB::transaction(function () use ($extraccion, $jobs, $total): void {
            foreach ($jobs as $job) {
                VentaExtraccionPagina::query()->insertOrIgnore($job);
            }

            $extraccion->update([
                'estado' => 'en_progreso',
                'ventas_total_estimado' => $total,
                'paginas_total' => count($jobs),
            ]);
        });

        VentaExtraccionPagina::query()
            ->where('extraccion_id', $extraccion->id)
            ->whereIn('estado', ['pendiente', 'en_progreso'])
            ->orderBy('pagina')
            ->pluck('id')
            ->each(fn (int $pageId) => ProcesarPaginaVentasJob::dispatch($extraccion->id, $pageId)->onQueue('ventas-pages'));
    }

    /**
     * Si el worker muere a mitad de proceso (kill externo, reinicio de sesión),
     * Laravel marca el job como fallido sin volver a ejecutar handle() — sin este
     * hook la extracción se queda en "en_progreso" para siempre y bloquea el botón
     * "Nueva extracción" (ver ExtraccionVentas::hayExtraccionEnProgreso()).
     */
    public function failed(?Throwable $exception): void
    {
        $extraccion = VentaExtraccion::find($this->extraccionId);

        if (! $extraccion || $extraccion->estado === 'completado') {
            return;
        }

        $extraccion->update([
            'estado' => 'fallido',
            'mensaje_error' => $exception?->getMessage() ?? 'El worker se detuvo antes de terminar la extracción.',
            'completado_at' => now(),
        ]);
    }

}
