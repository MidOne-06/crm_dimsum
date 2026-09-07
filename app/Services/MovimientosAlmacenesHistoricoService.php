<?php

namespace App\Services;

use App\Models\MovimientoAlmacenDetalle;
use App\Models\MovimientoAlmacenHistorico;
use App\Models\MovimientoAlmacenSincronizacion;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MovimientosAlmacenesHistoricoService
{
    public function iniciar(string $desde, string $hasta, array $locales = [], string $estado = '-1', string $estadoRecepcion = '-1', ?int $iniciadoPor = null): MovimientoAlmacenSincronizacion
    {
        return MovimientoAlmacenSincronizacion::create([
            'fecha_inicio' => Carbon::parse($desde)->toDateString(),
            'fecha_fin' => Carbon::parse($hasta)->toDateString(),
            'estado' => 'pendiente',
            'filtros' => ['locales' => array_values(array_map('strval', $locales)), 'estado' => $estado, 'estado_recepcion' => $estadoRecepcion],
            'iniciado_por' => $iniciadoPor,
        ]);
    }

    public function sincronizar(MovimientoAlmacenSincronizacion $sync, MovimientosAlmacenesGatewayClient $gateway): array
    {
        $desde = $sync->fecha_inicio->toDateString();
        $hasta = $sync->fecha_fin->toDateString();
        $storedFilters = (array) $sync->filtros;
        $locales = array_values(array_filter((array) ($storedFilters['locales'] ?? [])));
        // En la grilla en vivo, sin una selección explícita Restaurant limita
        // la respuesta al local de sesión. Para una extracción histórica, en
        // cambio, "sin locales" significa todos los locales permitidos: de lo
        // contrario se pierden movimientos creados desde otros orígenes.
        if ($locales === []) {
            $locales = array_values(array_filter(array_map(
                fn (array $local): string => (string) ($local['id'] ?? ''),
                $gateway->locales(),
            )));
        }
        $filters = [
            'pagina' => 1, 'registros' => 50, 'fecha_inicio' => $desde, 'fecha_fin' => $hasta,
            'estado' => (string) ($storedFilters['estado'] ?? '-1'),
            'estado_recepcion' => (string) ($storedFilters['estado_recepcion'] ?? '-1'),
            'buscar_segun' => '2',
        ];
        if ($locales !== []) $filters['locales'] = implode(',', $locales);

        $reanudando = $sync->estado === 'en_progreso' && $sync->paginas_procesadas > 0 && $sync->paginas_total > 0;
        $erroresPrevios = $reanudando ? $sync->errores : 0;
        if (! $reanudando) $sync->update(['estado' => 'en_progreso', 'iniciado_en' => now(), 'mensaje_error' => null]);

        try {
            if ($reanudando) {
                $pages = $sync->paginas_total;
                $first = null;
                $startPage = $sync->paginas_procesadas + 1;
                $saved = $sync->cabeceras_guardadas;
                $details = $sync->detalles_guardados;
                $failed = $sync->errores;
                $seen = MovimientoAlmacenHistorico::query()->where('sincronizacion_id', $sync->id)->pluck('restaurant_id')->all();
            } else {
                $first = $gateway->movimientos($filters);
                $total = (int) ($first['total'] ?? 0);
                $pages = max(1, (int) ceil($total / 50));
                $sync->update(['paginas_total' => $pages]);
                $startPage = 1; $saved = 0; $details = 0; $failed = 0; $seen = [];
            }

            $errors = []; $failedPages = []; $cancelled = false;
            for ($page = $startPage; $page <= $pages; $page++) {
                if (MovimientoAlmacenSincronizacion::query()->whereKey($sync->id)->value('estado') === 'cancelado') { $cancelled = true; break; }
                try { $result = $page === 1 ? $first : $gateway->movimientos([...$filters, 'pagina' => $page]); }
                catch (\Throwable $exception) { $failedPages[] = $page; $errors[] = "Página {$page}: {$exception->getMessage()}"; $sync->update(['paginas_procesadas' => $page, 'errores' => ++$failed]); continue; }

                foreach ($result['rows'] ?? [] as $row) {
                    $id = (string) ($row['id'] ?? '');
                    if ($id === '') continue;
                    $seen[] = $id;
                    try { $detail = $gateway->detalle($id); $this->guardar($sync, $row, $detail); $saved++; $details += count($detail['items'] ?? []); }
                    catch (\Throwable $exception) { $failed++; $errors[] = "Movimiento {$id}: {$exception->getMessage()}"; }
                }
                $sync->update(['paginas_procesadas' => $page, 'cabeceras_guardadas' => $saved, 'detalles_guardados' => $details, 'errores' => $failed]);
            }

            if ($cancelled) return ['sincronizacion_id' => $sync->id, 'cancelada' => true, 'paginas' => $pages, 'saved' => $saved, 'details' => $details, 'failed' => $failed];

            $deleted = 0;
            if ($failedPages === [] && $erroresPrevios === 0 && (string) ($storedFilters['estado'] ?? '-1') === '-1') {
                $deleted = $this->reconciliar($desde, $hasta, $locales, array_unique($seen));
            } elseif ($failedPages !== []) {
                $errors[] = 'Reconciliación omitida: existen páginas sin leer; no se eliminaron registros.';
            }
            $sync->update([
                'estado' => $errors === [] ? 'completado' : 'completado_con_errores',
                'cabeceras_guardadas' => $saved, 'detalles_guardados' => $details,
                'cabeceras_eliminadas' => $deleted, 'errores' => $failed,
                'mensaje_error' => $errors === [] ? null : implode("\n", $errors), 'completado_en' => now(),
            ]);
            return compact('pages', 'saved', 'details', 'failed', 'deleted') + ['sincronizacion_id' => $sync->id, 'paginas_fallidas' => $failedPages];
        } catch (\Throwable $exception) {
            $sync->update(['estado' => 'fallido', 'mensaje_error' => $exception->getMessage(), 'completado_en' => now()]);
            throw $exception;
        }
    }

    public function guardar(MovimientoAlmacenSincronizacion $sync, array $row, array $detail): MovimientoAlmacenHistorico
    {
        return DB::transaction(function () use ($sync, $row, $detail): MovimientoAlmacenHistorico {
            $movement = MovimientoAlmacenHistorico::withTrashed()->updateOrCreate(
                ['restaurant_id' => (string) $row['id']],
                [
                    'sincronizacion_id' => $sync->id, 'fecha' => $this->date($row['fecha'] ?? null),
                    'local_origen_id' => $row['localOrigenId'] ?? null, 'local_origen' => $row['localOrigen'] ?? null,
                    'almacen_origen_id' => $row['almacenOrigenId'] ?? null, 'almacen_origen' => $row['almacenOrigen'] ?? null,
                    'local_destino_id' => $row['localDestinoId'] ?? null, 'local_destino' => $row['localDestino'] ?? null,
                    'almacen_destino_id' => $row['almacenDestinoId'] ?? null, 'almacen_destino' => $row['almacenDestino'] ?? null,
                    'encargado' => $row['encargado'] ?? null, 'receptor' => $row['receptor'] ?? null,
                    'registrado_por' => $row['registradoPor'] ?? null, 'total_items' => $row['totalItems'] ?? 0,
                    'valorizado' => $row['valorizado'] ?? 0, 'estado_codigo' => $row['estadoCodigo'] ?? null,
                    'estado' => $row['estado'] ?? null, 'estado_recepcion_codigo' => $row['estadoRecepcionCodigo'] ?? null,
                    'estado_recepcion' => $row['estadoRecepcion'] ?? null, 'observacion' => $detail['observacion'] ?? null,
                    'payload_restaurant' => ['row' => $row, 'detail' => $detail], 'sincronizado_en' => now(),
                ],
            );
            if ($movement->trashed()) $movement->restore();

            $seen = [];
            foreach ((array) ($detail['items'] ?? []) as $position => $item) {
                $itemId = filled($item['id'] ?? null) ? (string) $item['id'] : 'fallback:'.sha1($movement->restaurant_id.'|'.$position.'|'.($item['codigo'] ?? '').'|'.($item['item'] ?? ''));
                $seen[] = $itemId;
                $line = MovimientoAlmacenDetalle::withTrashed()->updateOrCreate(
                    ['movimiento_id' => $movement->id, 'restaurant_id' => $itemId],
                    [
                        'item_id' => $item['itemId'] ?? null, 'codigo' => $item['codigo'] ?? null,
                        'item' => $item['item'] ?? null, 'presentacion' => $item['presentacion'] ?? null,
                        'unidad' => $item['unidad'] ?? null, 'cantidad' => $item['cantidad'] ?? 0,
                        'almacen_origen' => $item['almacenOrigen'] ?? null, 'almacen_destino' => $item['almacenDestino'] ?? null,
                        'valorizado' => $item['valorizado'] ?? 0, 'estado' => $item['estado'] ?? null,
                        'payload_restaurant' => $item,
                    ],
                );
                if ($line->trashed()) $line->restore();
            }
            $query = $movement->detalles();
            $seen === [] ? $query->delete() : $query->whereNotIn('restaurant_id', $seen)->delete();
            return $movement;
        });
    }

    private function reconciliar(string $desde, string $hasta, array $locales, array $seen): int
    {
        $query = MovimientoAlmacenHistorico::query()->whereBetween('fecha', [Carbon::parse($desde)->startOfDay(), Carbon::parse($hasta)->endOfDay()]);
        if ($locales !== []) $query->whereIn('local_origen_id', $locales);
        if ($seen !== []) $query->whereNotIn('restaurant_id', $seen);
        return $query->delete();
    }

    private function date(mixed $value): ?Carbon
    {
        try { return filled($value) ? Carbon::parse($value) : null; } catch (\Throwable) { return null; }
    }
}
