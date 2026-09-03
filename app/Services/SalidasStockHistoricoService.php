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

        // Reanudación incremental por página, igual que
        // GuiasInternasHistoricoService::sincronizar() -- si esta corrida ya
        // venía "en_progreso" con páginas guardadas (se cortó por un deploy,
        // un reinicio de worker, etc.), continúa desde la página siguiente en
        // vez de repetir todo el rango desde cero y perder tiempo/llamadas al
        // gateway. $seen se reconstruye desde lo ya persistido en BD porque
        // no se guarda aparte entre reanudaciones.
        $reanudando = $soporte->estado === 'en_progreso' && $soporte->paginas_procesadas > 0 && $soporte->paginas_total > 0;
        $erroresPrevios = $reanudando ? $soporte->errores : 0;

        if (! $reanudando) {
            $soporte->update(['estado' => 'en_progreso', 'iniciado_en' => now(), 'mensaje_error' => null]);
        }

        try {
            if ($reanudando) {
                $pages = $soporte->paginas_total;
                $first = null;
                $startPage = $soporte->paginas_procesadas + 1;
                $saved = $soporte->cabeceras_guardadas;
                $details = $soporte->detalles_guardados;
                $failed = $soporte->errores;
                $seen = SalidaStock::query()->where('sincronizacion_id', $soporte->id)->pluck('restaurant_id')->all();
            } else {
                $first = $gateway->salidas(['pagina' => 1, 'registros' => 50, 'fecha_inicio' => $desde, 'fecha_fin' => $hasta]);
                $pages = max(1, (int) ceil(((int) ($first['total'] ?? 0)) / 50));
                $soporte->update(['paginas_total' => $pages]);
                $startPage = 1;
                $saved = $details = $failed = 0;
                $seen = [];
            }
            $errors = [];
            $paginasFallidas = [];

            for ($page = $startPage; $page <= $pages; $page++) {
                try {
                    $result = $page === 1 && $first !== null ? $first : $gateway->salidas(['pagina' => $page, 'registros' => 50, 'fecha_inicio' => $desde, 'fecha_fin' => $hasta]);
                } catch (\Throwable $exception) {
                    // Página irrecuperable tras los reintentos del gateway:
                    // se registra el hueco y se continúa con el resto en vez
                    // de perder toda la corrida por una sola página.
                    $paginasFallidas[] = $page;
                    $errors[] = "Página {$page}: {$exception->getMessage()}";
                    $soporte->update(['paginas_procesadas' => $page, 'errores' => ++$failed]);
                    continue;
                }
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

            // Reconciliar solo es seguro si se leyeron todas las páginas --
            // con huecos, $seen está incompleto y se borrarían salidas
            // válidas que cayeron en una página no leída esta vez. Lo mismo
            // si un intento ANTERIOR a esta reanudación dejó errores: $seen
            // reconstruido desde BD no distingue eso, así que se sigue
            // omitiendo el borrado para no generar falsos positivos.
            $deleted = $paginasFallidas === [] && $erroresPrevios === 0 ? $this->reconciliarCabeceras($desde, $hasta, $seen) : 0;
            if ($paginasFallidas !== []) $errors[] = 'Reconciliación omitida: páginas sin leer '.implode(',', $paginasFallidas).' (no se eliminó nada para evitar falsos positivos).';
            elseif ($erroresPrevios > 0) $errors[] = 'Reconciliación omitida: un intento anterior de esta misma corrida tuvo '.$erroresPrevios.' error(es) antes de reanudar (no se eliminó nada para evitar falsos positivos).';
            $soporte->update([
                'estado' => $errors === [] ? 'completado' : 'completado_con_errores',
                'cabeceras_guardadas' => $saved, 'detalles_guardados' => $details,
                'cabeceras_eliminadas' => $deleted, 'errores' => $failed,
                'mensaje_error' => $errors === [] ? null : implode("\n", $errors), 'completado_en' => now(),
            ]);
            return compact('pages', 'saved', 'details', 'failed', 'deleted') + ['sincronizacion_id' => $soporte->id, 'paginas_fallidas' => $paginasFallidas];
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
