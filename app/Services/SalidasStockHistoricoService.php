<?php

namespace App\Services;

use App\Models\SalidaStock;
use App\Models\SalidaStockDetalle;
use App\Models\SalidaStockSincronizacion;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SalidasStockHistoricoService
{
    public function iniciar(string $desde, string $hasta): SalidaStockSincronizacion
    {
        return SalidaStockSincronizacion::query()->create([
            'fecha_inicio' => Carbon::parse($desde)->toDateString(),
            'fecha_fin' => Carbon::parse($hasta)->toDateString(),
            'estado' => 'pendiente',
        ]);
    }

    public function sincronizar(SalidaStockSincronizacion $soporte, SalidasStockGatewayClient $gateway): array
    {
        $desde = $soporte->fecha_inicio->toDateString();
        $hasta = $soporte->fecha_fin->toDateString();
        $soporte->update(['estado' => 'en_progreso', 'iniciado_en' => now(), 'mensaje_error' => null]);

        try {
            $first = $gateway->salidas(['pagina' => 1, 'registros' => 50, 'fecha_inicio' => $desde, 'fecha_fin' => $hasta]);
            $pages = max(1, (int) ceil(((int) ($first['total'] ?? 0)) / 50));
            $soporte->update(['paginas_total' => $pages]);
            $saved = $details = $failed = 0;
            $seen = [];
            $errors = [];

            for ($page = 1; $page <= $pages; $page++) {
                $result = $page === 1 ? $first : $gateway->salidas(['pagina' => $page, 'registros' => 50, 'fecha_inicio' => $desde, 'fecha_fin' => $hasta]);
                foreach ($result['rows'] ?? [] as $row) {
                    $restaurantId = (string) ($row['id'] ?? '');
                    if ($restaurantId === '') continue;
                    $seen[] = $restaurantId;
                    try {
                        $detail = $gateway->detalle($restaurantId);
                        $this->guardar($soporte, $row, $detail);
                        $saved++;
                        $details += count($detail['items'] ?? []);
                    } catch (\Throwable $exception) {
                        $failed++;
                        $errors[] = "Salida {$restaurantId}: {$exception->getMessage()}";
                    }
                }
                $soporte->update(['paginas_procesadas' => $page, 'cabeceras_guardadas' => $saved, 'detalles_guardados' => $details, 'errores' => $failed]);
            }

            $deleted = $this->reconciliarCabeceras($desde, $hasta, $seen);
            $soporte->update([
                'estado' => $failed === 0 ? 'completado' : 'completado_con_errores',
                'cabeceras_guardadas' => $saved, 'detalles_guardados' => $details,
                'cabeceras_eliminadas' => $deleted, 'errores' => $failed,
                'mensaje_error' => $errors === [] ? null : implode("\n", $errors), 'completado_en' => now(),
            ]);
            return compact('pages', 'saved', 'details', 'failed', 'deleted') + ['sincronizacion_id' => $soporte->id];
        } catch (\Throwable $exception) {
            $soporte->update(['estado' => 'fallido', 'mensaje_error' => $exception->getMessage(), 'completado_en' => now()]);
            throw $exception;
        }
    }

    public function guardar(SalidaStockSincronizacion $soporte, array $row, array $detail): SalidaStock
    {
        return DB::transaction(function () use ($soporte, $row, $detail): SalidaStock {
            $salida = SalidaStock::withTrashed()->updateOrCreate(
                ['restaurant_id' => (string) $row['id']],
                [
                    'sincronizacion_id' => $soporte->id, 'local_id' => $row['localId'] ?? null, 'local_nombre' => $row['local'] ?? null,
                    'fecha' => $this->date($row['fecha'] ?? null), 'hora' => $row['hora'] ?? null,
                    'responsable' => $row['responsable'] ?? null, 'categoria' => $row['categoria'] ?? null,
                    'importe' => $row['importe'] ?? 0, 'razon' => $row['razon'] ?? null, 'estado' => (string) ($row['estado'] ?? ''),
                    'payload_restaurant' => $detail['sourceData'] ?? $row['payloadRestaurant'] ?? null, 'sincronizado_en' => now(),
                ],
            );
            if ($salida->trashed()) $salida->restore();

            $seenDetails = [];
            foreach ($detail['items'] ?? [] as $position => $item) {
                $detailId = $this->detailRestaurantId($item, $position);
                $seenDetails[] = $detailId;
                $detalle = SalidaStockDetalle::withTrashed()->updateOrCreate(
                    ['salida_stock_id' => $salida->id, 'restaurant_id' => $detailId],
                    [
                        'item_id' => $item['itemId'] ?? null, 'item_codigo' => $item['itemCodigo'] ?? null, 'item' => $item['item'] ?? null,
                        'tipo' => $item['tipo'] ?? null, 'almacen_id' => $item['almacenId'] ?? null, 'almacen' => $item['almacen'] ?? null,
                        'unidad' => $item['unidad'] ?? null, 'cantidad' => $item['cantidad'] ?? 0, 'costo' => $item['costo'] ?? 0,
                        'total' => $item['total'] ?? 0, 'payload_restaurant' => $item['payloadRestaurant'] ?? null,
                    ],
                );
                if ($detalle->trashed()) $detalle->restore();
            }

            $detailsQuery = $salida->detalles();
            $seenDetails === [] ? $detailsQuery->delete() : $detailsQuery->whereNotIn('restaurant_id', $seenDetails)->delete();
            return $salida;
        });
    }

    public function pagina(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        return SalidaStock::query()->when($filters['desde'] ?? null, fn ($q, $v) => $q->whereDate('fecha', '>=', $v))
            ->when($filters['hasta'] ?? null, fn ($q, $v) => $q->whereDate('fecha', '<=', $v))
            ->when($filters['local'] ?? null, fn ($q, $v) => $q->where('local_id', $v))
            ->when($filters['categoria'] ?? null, fn ($q, $v) => $q->where('categoria', $v))
            ->orderByDesc('fecha')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
    }

    private function reconciliarCabeceras(string $desde, string $hasta, array $seen): int
    {
        $query = SalidaStock::query()->whereBetween('fecha', [$desde, $hasta]);
        if ($seen !== []) $query->whereNotIn('restaurant_id', array_values(array_unique($seen)));
        return $query->delete();
    }

    private function detailRestaurantId(array $item, int $position): string
    {
        if (filled($item['id'] ?? null)) return (string) $item['id'];
        if (filled($item['itemId'] ?? null)) return (string) $item['itemId'];
        return 'fallback:'.sha1(implode('|', [$position, $item['itemCodigo'] ?? '', $item['almacenId'] ?? '', $item['cantidad'] ?? '']));
    }

    private function date(mixed $value): ?Carbon
    {
        try { return filled($value) ? Carbon::parse($value) : null; } catch (\Throwable) { return null; }
    }
}
