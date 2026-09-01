<?php

namespace App\Services;

use App\Models\StockCuadre;
use App\Models\StockCuadreDetalle;
use App\Models\StockCuadreSoporte;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Copia local de los cuadres de Restaurant. El frontend nunca depende del gateway. */
class StockActualHistoricoService
{
    public function iniciar(array $filtros): StockCuadreSoporte
    {
        return StockCuadreSoporte::query()->create([
            'estado' => 'pendiente',
            'filtros' => $filtros,
        ]);
    }

    public function sincronizar(StockCuadreSoporte $soporte, StockGatewayClient $gateway): void
    {
        $filtros = $soporte->filtros ?? [];
        $base = array_merge([
            'locales' => '', 'estado' => '-1', 'tipo' => '-1',
            'fechaInicio' => '2020-01-01', 'fechaFin' => now()->toDateString(),
            'itemIdList' => '', 'itemTipoList' => '',
        ], $filtros);

        // Restaurant rechaza una consulta sin locales. Para la sincronización
        // completa se envían explícitamente todos los locales disponibles.
        if (blank($base['locales'])) {
            $base['locales'] = implode('-', array_column($gateway->locals(), 'id'));
        }

        $soporte->update(['estado' => 'en_progreso', 'iniciado_at' => now(), 'mensaje_error' => null]);
        $first = $gateway->cuadres(array_merge($base, ['pagina' => 1, 'registros' => 50]));
        $total = (int) ($first['total'] ?? 0);
        $pages = max(1, (int) ceil($total / 50));
        $soporte->update(['paginas_total' => $pages]);

        $cuadres = 0;
        $detalles = 0;
        $errores = [];
        for ($pagina = 1; $pagina <= $pages; $pagina++) {
            $result = $pagina === 1 ? $first : $gateway->cuadres(array_merge($base, ['pagina' => $pagina, 'registros' => 50]));
            foreach (($result['rows'] ?? []) as $fila) {
                $id = (string) ($fila['cuadremanual_id'] ?? '');
                if ($id === '') continue;

                try {
                    $detalle = $gateway->cuadreDetail($id);
                } catch (\Throwable $exception) {
                    // La cabecera sigue siendo útil localmente. Un detalle lento
                    // se reintentará en la siguiente ejecución sin detener toda
                    // la extracción de Restaurant.
                    $detalle = ['id' => $id, 'items' => []];
                    $errores[] = "Cuadre {$id}: {$exception->getMessage()}";
                }
                $this->guardarCuadre($soporte, $fila, $detalle);
                $cuadres++;
                $detalles += count($detalle['items'] ?? []);
                $soporte->update(['cuadres_guardados' => $cuadres, 'detalles_guardados' => $detalles]);
            }
            $soporte->update(['paginas_procesadas' => $pagina, 'cuadres_guardados' => $cuadres, 'detalles_guardados' => $detalles]);
        }

        $soporte->update(['estado' => $errores === [] ? 'completado' : 'completado_con_errores', 'mensaje_error' => $errores === [] ? null : implode("\n", $errores), 'completado_at' => now()]);
    }

    /** @param array<string,mixed> $fila @param array<string,mixed> $detalle */
    public function guardarCuadre(StockCuadreSoporte $soporte, array $fila, array $detalle): StockCuadre
    {
        $restaurantId = (string) ($fila['cuadremanual_id'] ?? $detalle['id'] ?? '');
        if ($restaurantId === '') throw new \RuntimeException('Restaurant no devolvió el identificador del cuadre.');

        return DB::transaction(function () use ($soporte, $fila, $detalle, $restaurantId): StockCuadre {
            $payload = array_merge($fila, ['detalle_restaurant' => $detalle['sourceData'] ?? $detalle]);
            $cuadre = StockCuadre::query()->updateOrCreate(['restaurant_id' => $restaurantId], [
                'soporte_id' => $soporte->id,
                'local_id' => $this->string($fila['local_id'] ?? ($detalle['sourceData']['local_id'] ?? null)),
                'local_nombre' => $this->string($fila['local_descripcion'] ?? $fila['cuadremanual_local'] ?? $detalle['local'] ?? null),
                'fecha_cuadre' => $this->date($fila['cuadremanual_fecha'] ?? $detalle['fechaCuadre'] ?? null),
                'fecha_registro' => $this->date($fila['cuadremanual_fecharegistro'] ?? $detalle['fechaRegistro'] ?? null),
                'estado' => $this->string($fila['estado'] ?? $detalle['estado'] ?? null),
                'tipo' => $this->string($fila['tipo_cuadre'] ?? $fila['tipo'] ?? null),
                'motivo' => $this->string($fila['cuadremanual_razon'] ?? $fila['motivo'] ?? $detalle['motivo'] ?? null),
                'responsable' => $this->string($fila['usuario_nombre'] ?? $detalle['registradoPor'] ?? null),
                'sobrevalorizacion' => $this->number($fila['sobrevalorizacion'] ?? $fila['cuadremanual_sobrevalorizacion'] ?? 0),
                'perdida' => $this->number($fila['perdida'] ?? $fila['cuadremanual_perdida'] ?? 0),
                'checksum' => hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE)),
                'payload_restaurant' => $payload,
                'sincronizado_en' => now(),
            ]);

            $ids = [];
            foreach (($detalle['items'] ?? []) as $posicion => $item) {
                $itemId = (string) ($item['id'] ?? $posicion);
                $ids[] = $itemId;
                $cuadre->detalles()->updateOrCreate(['restaurant_id' => $itemId], [
                    'item_id' => $this->string($item['itemId'] ?? $item['item_id'] ?? null),
                    'item_codigo' => $this->string($item['itemCodigo'] ?? $item['item_codigo'] ?? null),
                    'item' => $this->string($item['item'] ?? null),
                    'tipo' => $this->string($item['tipo'] ?? null),
                    'almacen_id' => $this->string($item['almacenId'] ?? $item['almacen_id'] ?? null),
                    'almacen' => $this->string($item['almacen'] ?? null),
                    'unidad' => $this->string($item['unidad'] ?? null),
                    'aumento' => $this->number($item['aumento'] ?? 0),
                    'disminucion' => $this->number($item['disminuyo'] ?? 0),
                    'costo' => $this->number($item['costo'] ?? 0),
                    'impuestos' => $this->number($item['impuestos'] ?? 0),
                    'total' => $this->number($item['total'] ?? 0),
                    'stock_anterior' => $this->number($item['stockAnterior'] ?? 0),
                    'stock_actual' => $this->number($item['stockActual'] ?? 0),
                    'valorizacion' => $this->number($item['valorizacion'] ?? 0),
                    'activo' => true,
                    'payload_restaurant' => $item['payloadRestaurant'] ?? $item,
                ]);
            }
            if ($ids !== []) $cuadre->detalles()->whereNotIn('restaurant_id', $ids)->update(['activo' => false]);

            return $cuadre;
        });
    }

    public function cuadres(array $filtros, int $page, int $perPage): LengthAwarePaginator
    {
        $query = $this->filteredCuadres($filtros)->orderByDesc('fecha_cuadre')->orderByDesc('restaurant_id');
        return $query->paginate($perPage, ['*'], 'cuadresPage', $page);
    }

    /** @return array<string,mixed> */
    public function header(array $filtros): array
    {
        $q = $this->filteredCuadres($filtros);
        return ['totalCuadres' => $q->count(), 'sobrevalorizacion' => (float) $q->sum('sobrevalorizacion'), 'perdida' => (float) $q->sum('perdida')];
    }

    /** @return array<int,array<string,mixed>> */
    public function maestro(array $filtros): array
    {
        $rows = $this->filteredCuadres($filtros)->with(['detalles' => fn ($q) => $q->where('activo', true)])->get();
        $master = [];
        foreach ($rows as $cuadre) foreach ($cuadre->detalles as $detalle) {
            $key = implode('|', [$cuadre->local_id, $detalle->almacen_id, $detalle->item_id, $detalle->unidad]);
            $order = (($cuadre->fecha_cuadre?->format('Y-m-d H:i:s')) ?? '').'|'.$cuadre->restaurant_id;
            if (isset($master[$key]) && $master[$key]['orden'] >= $order) continue;
            $master[$key] = ['itemId' => $detalle->item_id ?? '', 'itemCodigo' => $detalle->item_codigo ?? '', 'local' => $cuadre->local_nombre ?? '', 'almacen' => $detalle->almacen ?? '', 'item' => $detalle->item ?? '', 'tipo' => $detalle->tipo ?? '', 'unidad' => $detalle->unidad ?? '', 'stockActual' => (float) $detalle->stock_actual, 'fecha' => $cuadre->fecha_cuadre?->format('Y-m-d'), 'orden' => $order];
        }
        $result = array_values($master);
        usort($result, fn ($a, $b) => [$a['local'], $a['almacen'], $a['item']] <=> [$b['local'], $b['almacen'], $b['item']]);
        return $result;
    }

    private function filteredCuadres(array $filtros)
    {
        $q = StockCuadre::query();
        if ($inicio = $filtros['fechaInicio'] ?? null) $q->whereDate('fecha_cuadre', '>=', $inicio);
        if ($fin = $filtros['fechaFin'] ?? null) $q->whereDate('fecha_cuadre', '<=', $fin);
        $locales = array_filter(explode('-', (string) ($filtros['locales'] ?? '')));
        if ($locales) $q->whereIn('local_id', $locales);
        if (($filtros['estado'] ?? '-1') !== '-1') $q->where('estado', (string) $filtros['estado']);
        if (($filtros['tipo'] ?? '-1') !== '-1') $q->where('tipo', (string) $filtros['tipo']);
        $items = array_filter(explode('-', (string) ($filtros['itemIdList'] ?? '')));
        if ($items) $q->whereHas('detalles', fn ($d) => $d->whereIn('item_id', $items)->where('activo', true));
        return $q;
    }

    private function string(mixed $value): ?string { $value = trim((string) $value); return $value === '' ? null : $value; }
    private function number(mixed $value): float { return is_numeric($value) ? (float) $value : 0.0; }
    private function date(mixed $value): ?Carbon { try { return filled($value) ? Carbon::parse((string) $value) : null; } catch (\Throwable) { return null; } }
}
