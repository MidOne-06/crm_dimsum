<?php

namespace App\Services;

use App\Models\GuiaInterna;
use App\Models\GuiaInternaDetalle;
use App\Models\GuiaInternaSincronizacion;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class GuiasInternasHistoricoService
{
    public function iniciar(string $desde, string $hasta, array $locales = [], ?int $iniciadoPor = null): GuiaInternaSincronizacion
    {
        return GuiaInternaSincronizacion::create(['fecha_inicio' => Carbon::parse($desde)->toDateString(), 'fecha_fin' => Carbon::parse($hasta)->toDateString(), 'estado' => 'pendiente', 'filtros' => ['locales' => array_values(array_map('strval', $locales))], 'iniciado_por' => $iniciadoPor]);
    }

    public function sincronizar(GuiaInternaSincronizacion $sync, GuiasInternasGatewayClient $gateway, array $locales = [], string $estado = '-1'): array
    {
        $desde = $sync->fecha_inicio->toDateString(); $hasta = $sync->fecha_fin->toDateString(); $locales = array_values(array_filter($locales));
        $filters = ['pagina' => 1, 'registros' => 50, 'fecha_inicio' => $desde, 'fecha_fin' => $hasta, 'estado' => $estado];
        if ($locales !== []) $filters['locales'] = implode(',', $locales);
        $sync->update(['estado' => 'en_progreso', 'iniciado_en' => now(), 'mensaje_error' => null]);
        try {
            // Solo la página 1 es indispensable: sin ella no sabemos cuántas
            // páginas hay. El cliente ya reintenta 3 veces ante fallas
            // transitorias (ver GuiasInternasGatewayClient::get); si aun así
            // falla, no hay forma de continuar.
            $first = $gateway->guias($filters); $total = (int) ($first['total'] ?? 0); $pages = max(1, (int) ceil($total / 50)); $sync->update(['paginas_total' => $pages]);
            $saved = $details = $failed = 0; $seen = $errors = []; $paginasFallidas = [];
            for ($page = 1; $page <= $pages; $page++) {
                try {
                    $result = $page === 1 ? $first : $gateway->guias([...$filters, 'pagina' => $page]);
                } catch (\Throwable $e) {
                    // Una página irrecuperable (tras los reintentos del
                    // gateway) no debe tumbar las 58 páginas restantes: se
                    // registra como hueco explícito y se sigue. La corrida
                    // termina en 'completado_con_errores', nunca en silencio.
                    $paginasFallidas[] = $page;
                    $errors[] = "Página {$page}: {$e->getMessage()}";
                    $sync->update(['paginas_procesadas' => $page, 'errores' => ++$failed]);
                    continue;
                }
                foreach ($result['rows'] ?? [] as $row) {
                    $id = (string) ($row['id'] ?? ''); if ($id === '') continue; $seen[] = $id;
                    try { $detail = $gateway->detalle($id); $this->guardar($sync, $row, $detail); $saved++; $details += count($detail['items'] ?? []); }
                    catch (\Throwable $e) { $failed++; $errors[] = "Guía {$id}: {$e->getMessage()}"; }
                }
                $sync->update(['paginas_procesadas' => $page, 'cabeceras_guardadas' => $saved, 'detalles_guardados' => $details, 'errores' => $failed]);
            }
            // Reconciliar (borrar lo que ya no existe en Restaurant) solo es
            // seguro si TODAS las páginas se leyeron: con páginas fallidas,
            // $seen está incompleto y borraríamos guías válidas que cayeron
            // en una página que no pudimos leer esta vez.
            $deleted = $paginasFallidas === [] && $estado === '-1' ? $this->reconciliar($desde, $hasta, $seen, $locales) : 0;
            if ($paginasFallidas !== []) $errors[] = 'Reconciliación omitida: páginas sin leer '.implode(',', $paginasFallidas).' (no se eliminó nada para evitar falsos positivos).';
            $sync->update(['estado' => $errors === [] ? 'completado' : 'completado_con_errores', 'cabeceras_guardadas' => $saved, 'detalles_guardados' => $details, 'cabeceras_eliminadas' => $deleted, 'errores' => $failed, 'mensaje_error' => $errors === [] ? null : implode("\n", $errors), 'completado_en' => now()]);
            return compact('pages', 'saved', 'details', 'failed', 'deleted') + ['sincronizacion_id' => $sync->id, 'paginas_fallidas' => $paginasFallidas];
        } catch (\Throwable $e) { $sync->update(['estado' => 'fallido', 'mensaje_error' => $e->getMessage(), 'completado_en' => now()]); throw $e; }
    }

    public function guardar(GuiaInternaSincronizacion $sync, array $row, array $detail): GuiaInterna
    {
        return DB::transaction(function () use ($sync, $row, $detail): GuiaInterna {
            $guia = GuiaInterna::withTrashed()->updateOrCreate(['restaurant_id' => (string) $row['id']], ['sincronizacion_id' => $sync->id, 'serie' => $row['serie'] ?? null, 'correlativo' => $row['correlativo'] ?? null, 'local_origen_id' => $row['localOrigenId'] ?? null, 'local_origen' => $row['localOrigen'] ?? null, 'local_destino_id' => $row['localDestinoId'] ?? null, 'local_destino' => $row['localDestino'] ?? null, 'direccion_destino' => $detail['direccionDestino'] ?? null, 'direccion_destino_payload' => $detail['direccionDestinoPayload'] ?? null, 'almacen_id' => $row['almacenId'] ?? null, 'almacen' => $row['almacen'] ?? null, 'fecha_registro' => $this->date($row['fechaRegistro'] ?? null), 'fecha_emision' => $this->date($row['fechaEmision'] ?? null), 'fecha_traslado' => $this->date($row['fechaTraslado'] ?? null), 'motivo_id' => $row['motivoId'] ?? null, 'motivo' => $row['motivo'] ?? null, 'estado_codigo' => $row['estadoCodigo'] ?? null, 'estado' => $row['estado'] ?? null, 'recepcionada' => $row['recepcionada'] ?? null, 'pendiente_procesar_stock' => $row['pendienteProcesarStock'] ?? null, 'total' => $row['total'] ?? 0, 'total_items' => $row['totalItems'] ?? 0, 'requerimiento_restaurant_id' => $detail['requerimientoId'] ?? null, 'movimiento_restaurant_id' => $detail['movimientoId'] ?? null, 'observacion' => $detail['observacion'] ?? null, 'payload_restaurant' => $detail['sourceData'] ?? $row['payloadRestaurant'] ?? null, 'sincronizado_en' => now()]);
            if ($guia->trashed()) $guia->restore(); $seen = [];
            foreach ($detail['items'] ?? [] as $position => $item) {
                $itemId = filled($item['id'] ?? null) ? (string) $item['id'] : 'fallback:'.sha1($position.'|'.($item['itemId'] ?? '').'|'.($item['almacenId'] ?? '')); $seen[] = $itemId;
                $line = GuiaInternaDetalle::withTrashed()->updateOrCreate(['guia_interna_id' => $guia->id, 'restaurant_id' => $itemId], ['item_id' => $item['itemId'] ?? null, 'item_tipo' => $item['itemTipo'] ?? null, 'item_codigo' => $item['codigo'] ?? null, 'item' => $item['item'] ?? null, 'categoria' => $item['categoria'] ?? null, 'presentacion' => $item['presentacion'] ?? null, 'unidad' => $item['unidad'] ?? null, 'almacen_id' => $item['almacenId'] ?? null, 'almacen' => $item['almacen'] ?? null, 'cantidad' => $item['cantidad'] ?? 0, 'cantidad_salida' => $item['cantidadSalida'] ?? $item['cantidad'] ?? 0, 'stock' => $item['stock'] ?? null, 'peso' => $item['peso'] ?? 0, 'precio' => $item['precio'] ?? 0, 'descuento' => $item['descuento'] ?? 0, 'total' => $item['total'] ?? 0, 'pendiente_descarga_stock' => $item['pendienteDescargaStock'] ?? null, 'payload_restaurant' => $item['payloadRestaurant'] ?? null]);
                if ($line->trashed()) $line->restore();
            }
            $query = $guia->detalles(); $seen === [] ? $query->delete() : $query->whereNotIn('restaurant_id', $seen)->delete(); return $guia;
        });
    }

    private function reconciliar(string $desde, string $hasta, array $seen, array $locales = []): int
    {
        $query = GuiaInterna::query()->whereBetween('fecha_emision', [Carbon::parse($desde)->startOfDay(), Carbon::parse($hasta)->endOfDay()]);
        if ($locales !== []) $query->whereIn('local_origen_id', $locales); if ($seen !== []) $query->whereNotIn('restaurant_id', array_unique($seen)); return $query->delete();
    }

    private function date(mixed $value): ?Carbon
    {
        try { return filled($value) ? Carbon::parse($value) : null; } catch (\Throwable) { return null; }
    }
}
