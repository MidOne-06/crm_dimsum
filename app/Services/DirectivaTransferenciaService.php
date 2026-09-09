<?php

namespace App\Services;

use App\Models\DirectivaTransferenciaSugerencia;
use App\Models\LocalLogisticaConfig;
use App\Models\LocalLogisticaHorario;
use App\Models\ProductoPresentacionDespacho;
use App\Models\StockInicialLocal;
use App\Models\StockSaldoActual;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Motor de la Directiva de Transferencia -- cantidad sugerida a despachar
 * por local x ítem, para cubrir la demanda real hasta que llegue el
 * PRÓXIMO camión a cada local (no un día calendario genérico).
 *
 * Rediseño real del 2026-09-09, a pedido explícito del usuario, sobre el
 * diseño original documentado en la bitácora del 2026-09-07. Antes: un día
 * calendario completo, mismo día de semana, 12pm fijo para todos. Ahora:
 *
 * - **Frecuencia por local** (`LocalLogisticaConfig.frecuencia_dias`, 1 por
 *   defecto si el local no tiene configuración cargada): el próximo
 *   despacho de un local no es siempre "mañana" -- es
 *   `fecha_referencia + frecuencia_dias`. Un local que reparte cada 2 días
 *   se calcula para pasado mañana si hoy le tocó reparto, no para mañana.
 * - **Hora de llegada real, por local Y por día de semana**
 *   (`LocalLogisticaHorario`, con `LocalLogisticaConfig.hora_llegada_estimada`
 *   como default si no hay excepción para ese día, y 12:00 si tampoco hay
 *   eso) -- ver horaLlegada().
 * - **Ventana de demanda por tramo de horas real, no por día calendario**:
 *   el período a cubrir es exactamente
 *   `[hora de llegada de HOY, hora de llegada del día de destino)` -- ej.
 *   si hoy la reposición llegó a las 11am y la próxima llega mañana a las
 *   12pm, la ventana es "11am de hoy a 12pm de mañana" (cruza medianoche).
 *   `kardex_movimientos.fecha_hora` (timestamp real, no solo fecha) permite
 *   filtrar por esta hora exacta.
 * - **Promedio histórico sobre el MISMO tramo, semana a semana**: para
 *   preservar la estacionalidad por día de la semana (un sábado vende muy
 *   distinto a un martes), las últimas hasta 10 comparaciones históricas se
 *   arman retrocediendo de a **7 días exactos** (no `frecuencia_dias`) desde
 *   la fecha de referencia -- así cada comparación cae en el mismo par de
 *   días de la semana que la ventana real, con la misma duración
 *   (`frecuencia_dias` días), solo que 1, 2, 3... semanas atrás.
 *
 * El resto del diseño no cambia: universo de ítems (Presentación de
 * Despacho) y locales (Stock Inicial confirmado), stock proyectado = saldo
 * actual + tránsito, `cantidad_sugerida = redondear(max(0, demanda -
 * proyectado))`. Ver StockSaldoRecalculadorService para saldo_actual y el
 * docblock anterior de esta clase (recuperable en git) para el detalle del
 * cálculo de tránsito, que sigue igual salvo que ahora la fecha de destino
 * es la real de cada local, no una fecha global.
 */
class DirectivaTransferenciaService
{
    private const ALMACEN = 'Almacen Principal';

    private const MOTIVO_VENTA = 'SALIDA, POR VENTA.';

    private const COMPARACIONES_HISTORICAS = 10; // cuántas semanas atrás se comparan, como máximo

    private const HORA_POR_DEFECTO = '12:00:00';

    /**
     * @param  string  $fechaReferencia  El día en que se hace el cálculo
     *                                   ("hoy", en la práctica) -- cada local
     *                                   calcula su propia fecha de destino a
     *                                   partir de acá según su frecuencia.
     */
    public function calcularParaFecha(string $fechaReferencia): int
    {
        $hoy = Carbon::parse($fechaReferencia)->startOfDay();

        $productos = ProductoPresentacionDespacho::all()->keyBy(fn ($p) => "{$p->item_id}|{$p->item_tipo}");
        if ($productos->isEmpty()) {
            return 0;
        }
        $itemIds = $productos->pluck('item_id')->unique()->all();

        $locales = StockInicialLocal::where('estado', 'confirmado')->get();
        $configs = LocalLogisticaConfig::whereIn('local_id', $locales->pluck('local_id'))->get()->keyBy('local_id');
        $horarios = LocalLogisticaHorario::whereIn('local_id', $locales->pluck('local_id'))->get()->groupBy('local_id');

        $filas = [];
        $ahora = now();

        foreach ($locales as $local) {
            $config = $configs->get($local->local_id);
            $frecuencia = max(1, (int) ($config->frecuencia_dias ?? 1));
            $horariosLocal = $horarios->get($local->local_id, collect());

            $fechaDestino = $hoy->copy()->addDays($frecuencia);
            $horaOrigen = $this->horaLlegada($horariosLocal, $config, $hoy->dayOfWeekIso);
            $horaDestino = $this->horaLlegada($horariosLocal, $config, $fechaDestino->dayOfWeekIso);

            $desde = $hoy->copy()->setTimeFromTimeString($horaOrigen);
            $hasta = $fechaDestino->copy()->setTimeFromTimeString($horaDestino);

            // Ventanas históricas: mismo par de días de semana y misma
            // duración que [desde, hasta), retrocediendo de a 7 días exactos
            // -- ver docblock de la clase para por qué 7 y no frecuencia_dias.
            $ventanasHistoricas = [];
            for ($k = 1; $k <= self::COMPARACIONES_HISTORICAS; $k++) {
                $origenHist = $hoy->copy()->subWeeks($k);
                $destinoHist = $origenHist->copy()->addDays($frecuencia);
                $ventanasHistoricas[] = [
                    $origenHist->copy()->setTimeFromTimeString($horaOrigen),
                    $destinoHist->copy()->setTimeFromTimeString($horaDestino),
                ];
            }
            $limiteInferior = $ventanasHistoricas[array_key_last($ventanasHistoricas)][0];

            $ventasCrudas = DB::table('kardex_movimientos')
                ->where('local_id', $local->local_id)
                ->where('almacen', self::ALMACEN)
                ->where('motivo', self::MOTIVO_VENTA)
                ->whereIn('item_id', $itemIds)
                ->where('fecha_hora', '>=', $limiteInferior->toDateTimeString())
                ->where('fecha_hora', '<', $desde->toDateTimeString())
                ->selectRaw('item_id, tipo_item AS item_tipo, fecha_hora, salida')
                ->get()
                // fecha_hora vuelve como string del driver de Postgres --
                // se parsea UNA vez acá a Carbon para comparar de forma
                // confiable contra los límites de cada ventana histórica
                // (comparar strings crudos es frágil ante cualquier
                // diferencia de formato/zona horaria entre cómo Postgres
                // devuelve el valor y cómo Carbon arma el límite).
                ->map(function (object $row): object {
                    $row->fecha_hora = Carbon::parse($row->fecha_hora);

                    return $row;
                })
                ->groupBy(fn ($row) => "{$row->item_id}|{$row->item_tipo}");

            $saldos = StockSaldoActual::where('local_id', $local->local_id)
                ->whereIn('item_id', $itemIds)
                ->get()
                ->keyBy(fn ($s) => "{$s->item_id}|{$s->item_tipo}");

            // OJO: `guia_interna_detalles.item_tipo` viene de un endpoint de
            // Restaurant distinto al de Kardex y NO es la misma taxonomía --
            // ver docblock histórico de esta clase (git log). Cruzar solo
            // por item_id es seguro dentro del universo de despacho.
            $transitos = DB::table('guia_interna_detalles as d')
                ->join('guias_internas as g', 'g.id', '=', 'd.guia_interna_id')
                ->where('g.local_destino_id', $local->local_id)
                ->where('g.recepcionada', 'NO')
                ->whereDate('g.fecha_traslado', $fechaDestino->toDateString())
                ->whereIn('d.item_id', $itemIds)
                ->selectRaw('d.item_id, SUM(d.cantidad) AS cantidad_transito')
                ->groupBy('d.item_id')
                ->get()
                ->keyBy('item_id');

            foreach ($productos as $clave => $producto) {
                $ventasItem = $ventasCrudas->get($clave, collect());

                // Por cada ventana histórica, sumar las ventas cuyo
                // fecha_hora cae dentro de ese tramo puntual -- en memoria,
                // sobre lo ya traído, para no repetir 10 consultas por ítem.
                $demandasPorSemana = [];
                foreach ($ventanasHistoricas as [$desdeHist, $hastaHist]) {
                    $totalSemana = $ventasItem
                        ->filter(fn ($row): bool => $row->fecha_hora->gte($desdeHist) && $row->fecha_hora->lt($hastaHist))
                        ->sum('salida');
                    if ($totalSemana > 0) {
                        $demandasPorSemana[] = (float) $totalSemana;
                    }
                }
                $semanasConsideradas = count($demandasPorSemana);
                $demandaPromedio = $semanasConsideradas > 0 ? array_sum($demandasPorSemana) / $semanasConsideradas : 0.0;

                $saldoActual = (float) ($saldos->get($clave)?->saldo ?? 0);
                $cantidadEnTransito = (float) ($transitos->get($producto->item_id)?->cantidad_transito ?? 0);
                $stockProyectado = $saldoActual + $cantidadEnTransito;
                $cantidadBruta = max(0.0, $demandaPromedio - $stockProyectado);
                $cantidadSugerida = $producto->redondear($cantidadBruta);

                $filas[] = [
                    'fecha_despacho' => $fechaDestino->toDateString(),
                    'dia_semana' => $fechaDestino->dayOfWeekIso,
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

    /**
     * Hora de llegada real para un local en un día de semana puntual:
     * excepción cargada en LocalLogisticaHorario para ese día, si existe;
     * si no, hora_llegada_estimada de LocalLogisticaConfig; si tampoco hay
     * config cargada para el local (el módulo está vacío hoy en
     * producción, ver bitácora 2026-09-09), 12:00 por defecto -- mismo
     * comportamiento que el diseño anterior, para no romper nada mientras
     * se carga la configuración real.
     */
    /** @param  \Illuminate\Support\Collection<int, LocalLogisticaHorario>  $horariosLocal */
    private function horaLlegada(\Illuminate\Support\Collection $horariosLocal, ?LocalLogisticaConfig $config, int $diaSemana): string
    {
        $excepcion = $horariosLocal->firstWhere('dia_semana', $diaSemana);
        if ($excepcion) {
            return (string) $excepcion->hora;
        }

        return (string) ($config->hora_llegada_estimada ?? self::HORA_POR_DEFECTO);
    }
}
