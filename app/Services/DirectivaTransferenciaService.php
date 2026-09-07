<?php

namespace App\Services;

use App\Models\DirectivaTransferenciaSugerencia;
use App\Models\ProductoPresentacionDespacho;
use App\Models\StockInicialLocal;
use App\Models\StockSaldoActual;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Motor de la Directiva de Transferencia -- cantidad sugerida a despachar
 * por local x ítem, para llegar a las 12pm del día siguiente a como quede
 * el ciclo. Diseño validado con el usuario contra estándares reales de
 * reposición diaria (DSD -- Direct Store Delivery) y forecasting con
 * estacionalidad por día de la semana:
 *
 * - El cálculo corre de madrugada, ANTES de que el local abra -- a esa
 *   hora no existe ninguna venta del día en curso, así que no se puede
 *   "extrapolar" el día de hoy. Se usa en cambio el promedio histórico del
 *   MISMO día de la semana (si hoy es martes, el promedio de los últimos
 *   martes) -- un sábado vende muy distinto a un martes en un restaurante,
 *   mezclar los días distorsiona la demanda.
 * - Universo de ítems: solo los que tienen una `ProductoPresentacionDespacho`
 *   -- son los productos terminados que realmente se despachan a diario,
 *   no cada insumo del catálogo.
 * - Universo de locales: solo los que tienen `StockInicialLocal` confirmado
 *   -- sin eso no hay saldo real que restar.
 * - Stock proyectado = saldo actual + cantidad en tránsito. El tránsito sale
 *   de `guias_internas` (+ `guia_interna_detalles`) con fecha_traslado = la
 *   fecha que se está calculando y recepcionada='NO' -- mercadería que YA
 *   salió del local origen (ya se descontó allá) pero que Kardex del
 *   destino todavía no registra como recibida. Ojo: una guía recibida NO
 *   entra aquí -- ya quedó reflejada en saldo_actual, porque
 *   StockSaldoRecalculadorService suma TODA entrada de Kardex sin filtrar
 *   por motivo (confirmado con datos reales: la guía #1293 llegó a Kardex
 *   como "ENTRADA, POR REGULACION DE STOCK MANUAL.", no como
 *   "ENTRADA, POR GUIA." -- ese motivo está prácticamente en desuso, sin
 *   filas desde 2026-04-24). Sumar el tránsito puro además del saldo evitaría
 *   así un doble conteo si alguna vez se agrega un filtro por motivo al
 *   recalculador. El cruce con `guia_interna_detalles` se hace solo por
 *   item_id (no item_id+item_tipo) -- esa tabla trae su propio item_tipo
 *   numérico, de otro endpoint de Restaurant, que no es la misma taxonomía
 *   ni siquiera es estable por ítem (ver comentario en el método).
 * - Cantidad sugerida = max(0, demanda promedio - stock proyectado), redondeada
 *   al múltiplo de despacho del producto.
 * - Sin corrección de quiebre de stock todavía (un día en 0 de saldo real
 *   sesga el promedio hacia abajo) -- pendiente, no hay histórico diario
 *   de saldo guardado todavía para detectarlo. Ver bitácora.
 */
class DirectivaTransferenciaService
{
    private const ALMACEN = 'Almacen Principal';

    private const MOTIVO_VENTA = 'SALIDA, POR VENTA.';

    private const SEMANAS_VENTANA = 10; // ventana de búsqueda hacia atrás, en semanas

    public function calcularParaFecha(string $fechaDespacho): int
    {
        $fecha = Carbon::parse($fechaDespacho)->startOfDay();
        $diaSemana = $fecha->dayOfWeekIso; // 1=lunes .. 7=domingo, igual que ISODOW de Postgres
        $desde = $fecha->copy()->subWeeks(self::SEMANAS_VENTANA);

        $productos = ProductoPresentacionDespacho::all()->keyBy(fn ($p) => "{$p->item_id}|{$p->item_tipo}");
        if ($productos->isEmpty()) {
            return 0;
        }
        $itemIds = $productos->pluck('item_id')->unique()->all();

        $locales = StockInicialLocal::where('estado', 'confirmado')->get();
        $filas = [];
        $ahora = now();

        foreach ($locales as $local) {
            $ventas = DB::table('kardex_movimientos')
                ->where('local_id', $local->local_id)
                ->where('almacen', self::ALMACEN)
                ->where('motivo', self::MOTIVO_VENTA)
                ->whereIn('item_id', $itemIds)
                ->where('fecha', '>=', $desde->toDateString())
                ->where('fecha', '<', $fecha->toDateString())
                ->whereRaw('EXTRACT(ISODOW FROM fecha) = ?', [$diaSemana])
                ->selectRaw('item_id, tipo_item AS item_tipo, fecha, SUM(salida) AS salida_dia')
                ->groupBy('item_id', 'tipo_item', 'fecha')
                ->get()
                ->groupBy(fn ($row) => "{$row->item_id}|{$row->item_tipo}");

            $saldos = StockSaldoActual::where('local_id', $local->local_id)
                ->whereIn('item_id', $itemIds)
                ->get()
                ->keyBy(fn ($s) => "{$s->item_id}|{$s->item_tipo}");

            // OJO: `guia_interna_detalles.item_tipo` viene de un endpoint de
            // Restaurant distinto al de Kardex y NO es la misma taxonomía
            // (RECETA/PRODUCTO/INSUMO/DERIVADO/DESCARTABLE) -- ahí es un
            // código numérico (1/2) que ni siquiera es estable para un
            // mismo ítem entre guías (comprobado con datos reales: id 161
            // "CHA SIU" aparece con item_tipo 1 en una guía y 2 en otra).
            // Cruzar por item_tipo ahí simplemente nunca matchea. Como la
            // consulta ya está acotada a $itemIds (los 30 productos reales
            // de despacho, cada uno con item_id único -- verificado, sin
            // colisiones dentro de ese universo), agrupar solo por item_id
            // es seguro aunque en Kardex general sí se necesite el tipo.
            $transitos = DB::table('guia_interna_detalles as d')
                ->join('guias_internas as g', 'g.id', '=', 'd.guia_interna_id')
                ->where('g.local_destino_id', $local->local_id)
                ->where('g.recepcionada', 'NO')
                ->whereDate('g.fecha_traslado', $fecha->toDateString())
                ->whereIn('d.item_id', $itemIds)
                ->selectRaw('d.item_id, SUM(d.cantidad) AS cantidad_transito')
                ->groupBy('d.item_id')
                ->get()
                ->keyBy('item_id');

            foreach ($productos as $clave => $producto) {
                $dias = $ventas->get($clave, collect());
                $semanasConsideradas = $dias->count();
                $demandaPromedio = $semanasConsideradas > 0
                    ? (float) $dias->avg('salida_dia')
                    : 0.0;

                $saldoActual = (float) ($saldos->get($clave)?->saldo ?? 0);
                $cantidadEnTransito = (float) ($transitos->get($producto->item_id)?->cantidad_transito ?? 0);
                $stockProyectado = $saldoActual + $cantidadEnTransito;
                $cantidadBruta = max(0.0, $demandaPromedio - $stockProyectado);
                $cantidadSugerida = $producto->redondear($cantidadBruta);

                $filas[] = [
                    'fecha_despacho' => $fecha->toDateString(),
                    'dia_semana' => $diaSemana,
                    'local_id' => $local->local_id,
                    'local_nombre' => $local->local_nombre,
                    'item_id' => $producto->item_id,
                    'item_tipo' => $producto->item_tipo,
                    'item_codigo' => $producto->item_codigo,
                    'item_nombre' => $producto->item_nombre,
                    'demanda_promedio' => $demandaPromedio,
                    'semanas_consideradas' => $semanasConsideradas,
                    'saldo_actual' => $saldoActual,
                    'cantidad_en_transito' => $cantidadEnTransito,
                    'cantidad_bruta' => $cantidadBruta,
                    'multiplo_aplicado' => $producto->multiplo,
                    'cantidad_sugerida' => $cantidadSugerida,
                    'calculado_en' => $ahora,
                    'updated_at' => $ahora,
                    'created_at' => $ahora,
                ];
            }
        }

        foreach (array_chunk($filas, 500) as $lote) {
            DirectivaTransferenciaSugerencia::upsert(
                $lote,
                ['fecha_despacho', 'local_id', 'item_id', 'item_tipo'],
                ['local_nombre', 'item_codigo', 'item_nombre', 'demanda_promedio', 'semanas_consideradas',
                    'saldo_actual', 'cantidad_en_transito', 'cantidad_bruta', 'multiplo_aplicado',
                    'cantidad_sugerida', 'calculado_en', 'updated_at'],
            );
        }

        return count($filas);
    }
}
