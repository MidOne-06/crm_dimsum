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
 * - Cantidad sugerida = max(0, demanda promedio - saldo actual), redondeada
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

            foreach ($productos as $clave => $producto) {
                $dias = $ventas->get($clave, collect());
                $semanasConsideradas = $dias->count();
                $demandaPromedio = $semanasConsideradas > 0
                    ? (float) $dias->avg('salida_dia')
                    : 0.0;

                $saldoActual = (float) ($saldos->get($clave)?->saldo ?? 0);
                $cantidadBruta = max(0.0, $demandaPromedio - $saldoActual);
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
                    'saldo_actual', 'cantidad_bruta', 'multiplo_aplicado', 'cantidad_sugerida', 'calculado_en', 'updated_at'],
            );
        }

        return count($filas);
    }
}
