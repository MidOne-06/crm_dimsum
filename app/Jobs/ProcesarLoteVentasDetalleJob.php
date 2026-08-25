<?php

namespace App\Jobs;

use App\Models\Venta;
use App\Models\VentaDetalle;
use App\Models\VentaExtraccion;
use App\Models\VentaExtraccionVenta;
use App\Services\SalesGatewayClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcesarLoteVentasDetalleJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;
    public int $timeout = 300;
    public array $backoff = [30, 120, 300];

    /** @param array<int, int> $trabajoIds */
    public function __construct(public int $extraccionId, public array $trabajoIds)
    {
    }

    public function handle(SalesGatewayClient $gateway): void
    {
        $extraccion = VentaExtraccion::find($this->extraccionId);
        if (! $extraccion || $extraccion->estado !== 'en_progreso') {
            return;
        }

        $now = now();
        $trabajos = VentaExtraccionVenta::query()
            ->where('extraccion_id', $this->extraccionId)
            ->whereIn('id', $this->trabajoIds)
            ->where(function ($query): void {
                $query->where('estado', 'pendiente')
                    ->orWhere(fn ($stale) => $stale->where('estado', 'en_progreso')->where('locked_at', '<', now()->subMinutes(10)));
            })
            ->get();

        if ($trabajos->isEmpty()) {
            VentaExtraccion::finalizarSiListo($this->extraccionId);

            return;
        }

        $ids = $trabajos->pluck('id')->all();
        $claimed = VentaExtraccionVenta::query()
            ->whereIn('id', $ids)
            ->where(function ($query): void {
                $query->where('estado', 'pendiente')
                    ->orWhere(fn ($stale) => $stale->where('estado', 'en_progreso')->where('locked_at', '<', now()->subMinutes(10)));
            })
            ->update([
                'estado' => 'en_progreso',
                'locked_at' => $now,
                'attempts' => DB::raw('attempts + 1'),
            ]);

        if (! $claimed) {
            return;
        }

        // El UPDATE de arriba puede reclamar MENOS filas que $ids si otro
        // worker se adelantó a tomar parte del lote justo antes -- sin este
        // re-filtro, el resto del método seguía iterando sobre $trabajos (el
        // SELECT original) y terminaba escribiendo Venta/VentaDetalle para
        // ventas que este proceso no llegó a reclamar realmente.
        $claimedIds = VentaExtraccionVenta::query()
            ->whereIn('id', $ids)
            ->where('estado', 'en_progreso')
            ->where('locked_at', $now)
            ->pluck('id')
            ->all();

        if (empty($claimedIds)) {
            return;
        }

        $trabajos = $trabajos->whereIn('id', $claimedIds)->values();
        $ids = $claimedIds;

        try {
            $ventas = [];
            $detalles = [];

            foreach ($trabajos as $trabajo) {
                $detalle = $gateway->saleDetail($trabajo->venta_id);
                $row = $trabajo->resumen ?? [];
                $ventas[] = [
                    'venta_id' => $trabajo->venta_id,
                    'venta_fecha' => $row['venta_fecha'] ?? $now,
                    'local_id' => $row['local_id'] ?? null,
                    'local' => $row['local_descripcion'] ?? null,
                    'cliente_id' => $row['cliente_id'] ?? null,
                    'cliente' => $row['cliente_descripciion'] ?? null,
                    'cliente_ruc' => $row['cliente_dniruc'] ?? null,
                    'comprobante_tipo' => $row['venta_tipodoc'] ?? null,
                    'comprobante_serie' => $row['venta_seriedoc'] ?? null,
                    'comprobante_numero' => $row['venta_numdoc'] ?? null,
                    'moneda' => $row['moneda_descripcion'] ?? null,
                    'subtotal' => $row['venta_subtotal'] ?? 0,
                    'impuestos' => $row['impuestos'] ?? 0,
                    'total' => $row['venta_total'] ?? 0,
                    'forma_pago' => $row['venta_formapago'] ?? null,
                    'estado' => $row['venta_estado'] ?? null,
                    'usuario' => $row['usuario'] ?? null,
                    'raw' => json_encode($detalle['sourceData'] ?? null),
                    'synced_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                foreach ($detalle['items'] ?? [] as $item) {
                    $detalles[] = [
                        'venta_id' => $trabajo->venta_id,
                        'item_id' => isset($item['id']) ? (string) $item['id'] : null,
                        'descripcion' => $item['descripcion'] ?? null,
                        'cantidad' => $item['cantidad'] ?? 0,
                        'precio' => $item['precio'] ?? 0,
                        'descuento' => $item['descuento'] ?? 0,
                        'importe' => $item['importe'] ?? 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            DB::transaction(function () use ($extraccion, $ids, $ventas, $detalles, $now): void {
                Venta::upsert($ventas, ['venta_id']);
                VentaDetalle::whereIn('venta_id', array_column($ventas, 'venta_id'))->delete();

                foreach (array_chunk($detalles, 1000) as $chunk) {
                    VentaDetalle::insert($chunk);
                }

                VentaExtraccionVenta::whereIn('id', $ids)->update(['estado' => 'completado', 'locked_at' => null, 'updated_at' => $now]);
                VentaExtraccion::whereKey($extraccion->id)->update([
                    'ventas_guardadas' => DB::raw('ventas_guardadas + '.count($ventas)),
                    'items_guardados' => DB::raw('items_guardados + '.count($detalles)),
                    'ventas_procesadas' => DB::raw('ventas_procesadas + '.count($ventas)),
                    'updated_at' => $now,
                ]);
            });

            VentaExtraccion::finalizarSiListo($extraccion->id);
        } catch (Throwable $exception) {
            VentaExtraccionVenta::whereIn('id', $ids)->where('estado', 'en_progreso')->update(['estado' => 'pendiente', 'locked_at' => null]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $fallidas = VentaExtraccionVenta::query()
            ->where('extraccion_id', $this->extraccionId)
            ->whereIn('id', $this->trabajoIds)
            ->whereIn('estado', ['pendiente', 'en_progreso'])
            ->update(['estado' => 'fallido', 'locked_at' => null]);

        if ($fallidas) {
            VentaExtraccion::whereKey($this->extraccionId)->update([
                'ventas_fallidas' => DB::raw('ventas_fallidas + '.$fallidas),
                'ventas_procesadas' => DB::raw('ventas_procesadas + '.$fallidas),
            ]);
        }

        VentaExtraccion::finalizarSiListo($this->extraccionId);
    }
}
