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
 * - **Ventana de demanda EXACTA, desde el instante real del cálculo** --
 *   la ventana arranca en el momento REAL en que se ejecuta el cálculo
 *   (`now()`), sea la hora que sea (no una hora de llegada configurada
 *   de hoy). `kardex_movimientos.fecha_hora` (timestamp real, no solo
 *   fecha) permite filtrar por esto.
 * - **La ventana cubre 2 tramos, no 1** -- corrección real del 2026-09-09,
 *   entendida junto con el usuario a lo largo de varios ejemplos numéricos
 *   propios suyos. Lo que se despacha HOY llega recién MAÑANA -- así que
 *   no puede cubrir la demanda de "ahora a mañana" (eso lo tiene que
 *   aguantar por sí solo el stock proyectado actual, sin ayuda). Lo que sí
 *   tiene que cubrir es la demanda de "mañana (cuando llega) a pasado
 *   mañana (la próxima llegada)". Por eso la ventana real que alimenta
 *   `demanda_promedio` va de AHORA a PASADO MAÑANA (2 ciclos de
 *   `frecuencia_dias`, no 1) -- matemáticamente equivale a sumar ambos
 *   tramos y restar una sola vez el stock proyectado, lo cual además
 *   arrastra correctamente cualquier sobrante del primer tramo hacia el
 *   segundo (ver el método `calcularParaFecha()` para el detalle).
 * - **`demanda_ventana1` y `riesgo_quiebre`**: además de la ventana
 *   completa, se calcula aparte la demanda de SOLO el primer tramo (ahora
 *   -> mañana). Si esa demanda ya supera el stock proyectado actual, hay
 *   un quiebre real e INEVITABLE antes de que llegue la reposición de
 *   mañana -- ningún despacho de hoy lo puede evitar, porque llega tarde
 *   para eso. Se guarda como aviso aparte (`riesgo_quiebre=true`), no
 *   escondido dentro del número final de `cantidad_sugerida`.
 * - **Promedio histórico sobre el MISMO instante relativo, semana a
 *   semana**: como el límite superior real (`$hasta`) SÍ es una hora fija
 *   y conocida, retroceder ese límite de a 7 días exactos preserva el día
 *   de semana Y la hora exactos en cada comparación histórica. El límite
 *   inferior de cada comparación histórica se arma retrocediendo la MISMA
 *   cantidad de días desde el momento real de cálculo
 *   (`now()->subWeeks($k)`) -- así cada semana comparada usa el mismo par
 *   (día de semana, hora del día) que la ventana real, aunque el cálculo
 *   de hoy se haya disparado a una hora distinta que el de la semana
 *   pasada.
 *
 * El resto del diseño no cambia: universo de ítems (Presentación de
 * Despacho) y locales (Stock Inicial confirmado), stock proyectado = saldo
 * actual + tránsito, `cantidad_sugerida = redondear(max(0, demanda -
 * proyectado))`. Ver StockSaldoRecalculadorService para saldo_actual.
 * cantidad_en_tránsito: guías sin recepcionar con `fecha_traslado` entre
 * HOY y la fecha de destino del PRIMER tramo (mañana), ambas incluidas --
 * no se extiende a la ventana completa (pasado mañana) porque una guía con
 * traslado a pasado mañana normalmente ni existe todavía al momento de
 * calcular hoy.
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

            // Tramo 1: ahora -> mañana (cuando llega LO YA DESPACHADO
            // antes de hoy). El stock proyectado actual tiene que
            // aguantar este tramo por sí solo.
            $fechaDestino = $hoy->copy()->addDays($frecuencia);
            $horaDestino = $this->horaLlegada($horariosLocal, $config, $fechaDestino->dayOfWeekIso);
            $hastaV1 = $fechaDestino->copy()->setTimeFromTimeString($horaDestino);

            // Tramo 2: mañana -> pasado mañana (cuando llega la PRÓXIMA
            // reposición después de la de mañana). Esto es lo que el
            // despacho de HOY tiene que cubrir -- ver docblock de la clase.
            $fechaDestino2 = $fechaDestino->copy()->addDays($frecuencia);
            $horaDestino2 = $this->horaLlegada($horariosLocal, $config, $fechaDestino2->dayOfWeekIso);
            $hasta = $fechaDestino2->copy()->setTimeFromTimeString($horaDestino2);

            // Precisión pedida explícitamente por el usuario: la ventana
            // arranca AHORA MISMO (el instante real en que corre el
            // cálculo), no en una hora de llegada configurada de hoy.
            $desde = $ahora->copy();

            // Ventanas históricas de la ventana COMPLETA (ahora -> pasado
            // mañana) y del tramo 1 solo (ahora -> mañana), retrocediendo
            // de a 7 días exactos desde el momento real de cálculo -- así
            // cada semana comparada usa el mismo par (día de semana, hora
            // del día) que la ventana real, ver docblock de la clase.
            $ventanasHistoricas = [];
            $ventanasHistoricasV1 = [];
            for ($k = 1; $k <= self::COMPARACIONES_HISTORICAS; $k++) {
                $ventanasHistoricas[] = [$desde->copy()->subWeeks($k), $hasta->copy()->subWeeks($k)];
                $ventanasHistoricasV1[] = [$desde->copy()->subWeeks($k), $hastaV1->copy()->subWeeks($k)];
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
                ->whereDate('g.fecha_traslado', '>=', $hoy->toDateString())
                ->whereDate('g.fecha_traslado', '<=', $fechaDestino->toDateString())
                ->whereIn('d.item_id', $itemIds)
                ->selectRaw('d.item_id, SUM(d.cantidad) AS cantidad_transito')
                ->groupBy('d.item_id')
                ->get()
                ->keyBy('item_id');

            foreach ($productos as $clave => $producto) {
                $ventasItem = $ventasCrudas->get($clave, collect());

                // OJO, bug real encontrado revalidando en producción
                // (2026-09-09): promediar la ventana completa y el tramo 1
                // como 2 promedios INDEPENDIENTES (cada uno descartando sus
                // propias semanas en cero) podía dar demanda_promedio TOTAL
                // menor que demanda_ventana1 -- matemáticamente imposible,
                // porque el total incluye el tramo 1 adentro. Pasa porque
                // una semana con tramo1=0 (se descarta de ese promedio) puede
                // tener tramo2>0 (entra al promedio total con un valor bajo),
                // arrastrando el promedio total hacia abajo por una semana
                // que el otro promedio ni contaba. Fix: se decide UNA sola
                // vez qué semanas cuentan (las que tuvieron venta real en la
                // ventana COMPLETA) y se promedian AMBOS tramos sobre ese
                // mismo conjunto -- así el total nunca puede dar menos que
                // el tramo 1 que ya contiene adentro.
                $totalesCompletos = $this->totalesPorSemana($ventasItem, $ventanasHistoricas);
                $totalesV1 = $this->totalesPorSemana($ventasItem, $ventanasHistoricasV1);

                $semanasConsideradas = 0;
                $sumaCompleta = 0.0;
                $sumaV1 = 0.0;
                foreach ($totalesCompletos as $i => $totalCompleto) {
                    if ($totalCompleto <= 0) {
                        continue;
                    }
                    $semanasConsideradas++;
                    $sumaCompleta += $totalCompleto;
                    $sumaV1 += $totalesV1[$i];
                }
                $demandaPromedio = $semanasConsideradas > 0 ? $sumaCompleta / $semanasConsideradas : 0.0;
                $demandaVentana1 = $semanasConsideradas > 0 ? $sumaV1 / $semanasConsideradas : 0.0;

                $saldoActual = (float) ($saldos->get($clave)?->saldo ?? 0);
                $cantidadEnTransito = (float) ($transitos->get($producto->item_id)?->cantidad_transito ?? 0);
                $stockProyectado = $saldoActual + $cantidadEnTransito;

                // Riesgo de quiebre real e inevitable: si SOLO el tramo 1
                // (ahora->mañana) ya supera lo que hay proyectado, el local
                // se queda sin stock ANTES de que llegue la reposición de
                // mañana -- lo que se despache hoy llega tarde para evitar
                // ese hueco puntual.
                $riesgoQuiebre = $demandaVentana1 > $stockProyectado;

                // cantidad_bruta usa la ventana COMPLETA (ahora->pasado
                // mañana) contra el stock proyectado UNA sola vez -- esto
                // arrastra correctamente cualquier sobrante (o faltante)
                // del tramo 1 hacia el tramo 2, en vez de asumir que el
                // tramo 1 se cubre exacto sin sobrar ni faltar nada.
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
                    'demanda_ventana1' => $demandaVentana1,
                    'riesgo_quiebre' => $riesgoQuiebre,
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
                ['local_nombre', 'item_codigo', 'item_nombre', 'demanda_promedio', 'demanda_ventana1',
                    'riesgo_quiebre', 'semanas_consideradas', 'saldo_actual', 'cantidad_en_transito',
                    'cantidad_bruta', 'multiplo_aplicado', 'cantidad_sugerida', 'calculado_en', 'updated_at'],
            );
        }

        return count($filas);
    }

    /**
     * Suma la venta real dentro de cada ventana dada, en memoria sobre las
     * filas ya traídas de Kardex -- devuelve el total CRUDO de cada semana,
     * en el mismo orden que `$ventanas`, SIN descartar las que dieron 0.
     * El llamador decide qué semanas cuentan para el promedio (ver el
     * comentario en el bucle principal sobre por qué no se filtra acá).
     *
     * @param  \Illuminate\Support\Collection<int, object>  $ventasItem
     * @param  array<int, array{0: Carbon, 1: Carbon}>  $ventanas
     * @return array<int, float>
     */
    private function totalesPorSemana(\Illuminate\Support\Collection $ventasItem, array $ventanas): array
    {
        return array_map(
            fn (array $ventana): float => (float) $ventasItem
                ->filter(fn ($row): bool => $row->fecha_hora->gte($ventana[0]) && $row->fecha_hora->lt($ventana[1]))
                ->sum('salida'),
            $ventanas,
        );
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
