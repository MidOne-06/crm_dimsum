<?php

namespace App\Jobs;

use App\Models\VentaExtraccion;
use App\Models\VentaExtraccionPagina;
use App\Models\VentaExtraccionVenta;
use App\Services\SalesGatewayClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcesarPaginaVentasJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;
    public int $timeout = 300;
    public array $backoff = [30, 120, 300];

    public function __construct(public int $extraccionId, public int $paginaId)
    {
    }

    public function handle(SalesGatewayClient $gateway): void
    {
        $extraccion = VentaExtraccion::find($this->extraccionId);
        $pagina = VentaExtraccionPagina::whereKey($this->paginaId)->where('extraccion_id', $this->extraccionId)->first();

        if (! $extraccion || ! $pagina || $extraccion->estado !== 'en_progreso') {
            return;
        }

        $claimed = VentaExtraccionPagina::whereKey($pagina->id)->where('estado', 'pendiente')->update(['estado' => 'en_progreso']);
        if (! $claimed) {
            return;
        }

        try {
            $result = $gateway->sales([...(array) $extraccion->filtros, 'fechaInicio' => $pagina->fecha_inicio, 'fechaFin' => $pagina->fecha_fin, 'pagina' => $pagina->pagina, 'registros' => 200]);

            $trabajoIds = [];
            foreach (($result['rows'] ?? []) as $row) {
            $ventaId = (string) ($row['venta_id'] ?? '');
            if ($ventaId === '') {
                continue;
            }

            $created = VentaExtraccionVenta::query()->insertOrIgnore([
                'extraccion_id' => $extraccion->id,
                'venta_id' => $ventaId,
                'estado' => 'pendiente',
                'resumen' => json_encode($row, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($created) {
                $workId = VentaExtraccionVenta::where('extraccion_id', $extraccion->id)->where('venta_id', $ventaId)->value('id');
                $trabajoIds[] = $workId;
            }
            }

            foreach (array_chunk($trabajoIds, 25) as $lote) {
                ProcesarLoteVentasDetalleJob::dispatch($extraccion->id, $lote)->onQueue('ventas-details');
            }

            $pagina->update(['estado' => 'completado']);
            $extraccion->increment('paginas_procesadas');
            VentaExtraccion::finalizarSiListo($extraccion->id);
        } catch (Throwable $exception) {
            $pagina->update(['estado' => 'pendiente']);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        VentaExtraccionPagina::whereKey($this->paginaId)->update(['estado' => 'fallido']);
        VentaExtraccion::whereKey($this->extraccionId)->whereNotIn('estado', ['completado', 'fallido'])->update([
            'estado' => 'fallido',
            'mensaje_error' => $exception?->getMessage() ?? 'No se pudo procesar una página de ventas.',
            'completado_at' => now(),
        ]);
    }
}
