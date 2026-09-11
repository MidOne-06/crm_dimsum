<?php

namespace App\Services;

use App\Models\DirectivaTransferenciaSugerencia;
use App\Models\LocalActivoOverride;
use App\Models\LocalDiaSinDt;
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
 * - **Días sin DT, por local** (`LocalDiaSinDt`) -- pedido explícito del
 *   usuario con un caso real: un local puede no generar DT ciertos días de
 *   la semana (ej. sábado), sea cual sea el motivo operativo. Si hoy es un
 *   día "sin DT" para un local, ese local se SALTA por completo en esta
 *   corrida -- no se genera ninguna fila para él. La consecuencia real es
 *   que el día SIGUIENTE a un día sin DT tampoco tiene llegada de
 *   transporte (nada se despachó para eso) -- ver `proximaLlegada()`, que
 *   calcula la fecha de destino real caminando día por día y saltando
 *   cualquier día sin llegada, en vez de sumar un número fijo de días.
 *   `LocalLogisticaConfig.frecuencia_dias` queda como valor de referencia
 *   histórico pero ya no se usa para el cálculo -- lo reemplaza por
 *   completo esta lógica de días sin DT (vacía = local diario, sin
 *   excepciones, mismo comportamiento que antes).
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

    // Ver localesConVentaActiva() -- ventana corta a propósito, corregida
    // tras probar en producción real (2026-09-09): con 30 días y CUALQUIER
    // ítem del catálogo (360 por local, no solo los de despacho), los 5
    // locales ya confirmados como cerrados por el usuario (Primavera,
    // Metro Chorrillos, Villa María, KM 40, Puntamar) seguían contando como
    // "activos" -- Restaurant sigue teniendo movimientos de otros ítems
    // hasta hace pocos días. Medido el gap real: los 27 locales que sí
    // operan vendieron un ítem de despacho HOY MISMO (todos, sin excepción,
    // en las últimas horas); de los 5 cerrados, el más "reciente" (KM 40)
    // no vende un ítem de despacho hace 6 días, y el resto entre 9 días y
    // 4 meses -- 3 días separa limpio ambos grupos sin arriesgar un falso
    // "cerrado" por un día flojo de ventas.
    private const DIAS_VENTANA_VENTA_ACTIVA = 3;

    /**
     * @param  string  $fechaReferencia  El día en que se hace el cálculo
     *                                   ("hoy", en la práctica) -- cada local
     *                                   calcula su propia fecha de destino a
     *                                   partir de acá según su frecuencia.
     * @param  array<int, string>  $localesExcluidos  IDs de local a saltar por
     *                                   completo en esta corrida -- pedido
     *                                   explícito del usuario para el wizard
     *                                   "Iniciar Directiva de Transferencia"
     *                                   (2026-09-09): permite recalcular un
     *                                   subconjunto sin tocar el resto, algo
     *                                   que antes no existía (la corrida
     *                                   siempre tocaba los 32 locales
     *                                   confirmados). Vacío = ninguno
     *                                   excluido (comportamiento de siempre).
     * @param  bool  $soloVentaActiva  Si es true, además de $localesExcluidos,
     *                                   se descartan los locales SIN ninguna
     *                                   venta real en los últimos
     *                                   self::DIAS_VENTANA_VENTA_ACTIVA días
     *                                   -- ver localesConVentaActiva().
     * @param  float  $porcentajeAjusteGlobal  % a sumar sobre la cantidad
     *                                   bruta de TODOS los locales que no
     *                                   tengan su propio % en
     *                                   $porcentajeAjustePorLocal (ej. 10.0
     *                                   = +10%). 0 = sin ajuste (de siempre).
     * @param  array<string, float>  $porcentajeAjustePorLocal  local_id => %,
     *                                   pisa el global para ese local puntual.
     */
    public function calcularParaFecha(
        string $fechaReferencia,
        array $localesExcluidos = [],
        bool $soloVentaActiva = false,
        float $porcentajeAjusteGlobal = 0.0,
        array $porcentajeAjustePorLocal = [],
    ): int {
        $hoy = Carbon::parse($fechaReferencia)->startOfDay();

        $productos = ProductoPresentacionDespacho::all()->keyBy(fn ($p) => "{$p->item_id}|{$p->item_tipo}");
        if ($productos->isEmpty()) {
            return 0;
        }
        $itemIds = $productos->pluck('item_id')->unique()->all();

        $localesQuery = StockInicialLocal::where('estado', 'confirmado');
        if ($localesExcluidos !== []) {
            $localesQuery->whereNotIn('local_id', $localesExcluidos);
        }
        $locales = $localesQuery->get();

        if ($soloVentaActiva) {
            $activos = $this->localesActivos($locales->pluck('local_id')->all());
            $locales = $locales->whereIn('local_id', $activos)->values();
        }

        $configs = LocalLogisticaConfig::whereIn('local_id', $locales->pluck('local_id'))->get()->keyBy('local_id');
        $horarios = LocalLogisticaHorario::whereIn('local_id', $locales->pluck('local_id'))->get()->groupBy('local_id');
        $diasSinDt = LocalDiaSinDt::whereIn('local_id', $locales->pluck('local_id'))->get()->groupBy('local_id');

        $filas = [];
        $ahora = now();

        foreach ($locales as $local) {
            $config = $configs->get($local->local_id);
            $horariosLocal = $horarios->get($local->local_id, collect());
            $diasSinDtLocal = $diasSinDt->get($local->local_id, collect())->pluck('dia_semana')->all();

            // Si hoy es un día "sin DT" para este local, se salta por
            // completo -- no se genera ninguna sugerencia para él en esta
            // corrida. Ver docblock de la clase.
            if (in_array($hoy->dayOfWeekIso, $diasSinDtLocal, true)) {
                continue;
            }

            // Tramo 1: ahora -> mañana (cuando llega LO YA DESPACHADO
            // antes de hoy). El stock proyectado actual tiene que
            // aguantar este tramo por sí solo. "Mañana" es la próxima
            // fecha con llegada real, no necesariamente hoy+1 -- ver
            // proximaLlegada().
            $fechaDestino = $this->proximaLlegada($hoy, $diasSinDtLocal);
            $horaDestino = $this->horaLlegada($horariosLocal, $config, $fechaDestino->dayOfWeekIso);
            $hastaV1 = $fechaDestino->copy()->setTimeFromTimeString($horaDestino);

            // Tramo 2: mañana -> pasado mañana (cuando llega la PRÓXIMA
            // reposición después de la de mañana). Esto es lo que el
            // despacho de HOY tiene que cubrir -- ver docblock de la clase.
            $fechaDestino2 = $this->proximaLlegada($fechaDestino, $diasSinDtLocal);
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

                // Ajuste dinámico opcional (wizard "Iniciar Directiva de
                // Transferencia", pedido explícito del usuario): se aplica
                // DESPUÉS de la fórmula real, sobre `cantidad_bruta` ya
                // calculada -- nunca sobre la demanda ni el stock
                // proyectado, para no ensuciar esa trazabilidad ya auditada.
                // `cantidad_bruta` queda intacta como el número "limpio";
                // `cantidad_bruta_ajustada` es la que de verdad se redondea.
                $porcentajeAjuste = $porcentajeAjustePorLocal[$local->local_id] ?? $porcentajeAjusteGlobal;
                $cantidadBrutaAjustada = $cantidadBruta * (1 + ($porcentajeAjuste / 100));
                $cantidadSugerida = $producto->redondear($cantidadBrutaAjustada);

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
                    'porcentaje_ajuste_aplicado' => $porcentajeAjuste,
                    'cantidad_bruta_ajustada' => $cantidadBrutaAjustada,
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
                    'cantidad_bruta', 'porcentaje_ajuste_aplicado', 'cantidad_bruta_ajustada',
                    'multiplo_aplicado', 'cantidad_sugerida', 'calculado_en', 'updated_at'],
            );
        }

        return count($filas);
    }

    /**
     * IDs de local (de entre los dados) que vendieron al menos UN ítem de
     * despacho (Presentación de Despacho -- el mismo universo que calcula
     * la Directiva, no cualquier ítem del catálogo de Restaurant) en los
     * últimos self::DIAS_VENTANA_VENTA_ACTIVA días -- criterio de "venta
     * activa" del wizard "Iniciar Directiva de Transferencia", pedido
     * explícito del usuario para poder excluir de un cálculo masivo los
     * locales que hoy no operan (ver bitácora 2026-09-09: 5 locales reales
     * confirmados como cerrados, con stock y saldo en cero desde la carga
     * inicial) sin tener que desconfirmarlos ni tocar Stock Inicial.
     *
     * @param  array<int, string>  $localesIds
     * @return array<int, string>
     */
    public function localesConVentaActiva(array $localesIds): array
    {
        if ($localesIds === []) {
            return [];
        }

        $productos = ProductoPresentacionDespacho::get(['item_id', 'item_tipo']);
        if ($productos->isEmpty()) {
            return [];
        }

        return DB::table('kardex_movimientos')
            ->where('almacen', self::ALMACEN)
            ->where('motivo', self::MOTIVO_VENTA)
            ->whereIn('local_id', $localesIds)
            ->where('fecha_hora', '>=', now()->subDays(self::DIAS_VENTANA_VENTA_ACTIVA)->toDateTimeString())
            // item_id NO es único por sí solo (Restaurant lo reutiliza para
            // productos distintos según tipo_item, ver docblock histórico
            // de esta clase) -- hay que cruzar por el par exacto, igual que
            // el resto del cálculo.
            ->where(function ($query) use ($productos): void {
                foreach ($productos as $producto) {
                    $query->orWhere(fn ($q) => $q->where('item_id', $producto->item_id)->where('tipo_item', $producto->item_tipo));
                }
            })
            ->distinct()
            ->pluck('local_id')
            ->all();
    }

    /**
     * "Locales activos" -- pedido explícito del usuario tras el incidente
     * real del 2026-09-11 (una corrida manual dejó cantidades de despacho
     * calculadas para 4 tiendas cerradas). Combina la señal automática
     * (`localesConVentaActiva()`, venta real de despacho en los últimos 3
     * días -- dinámica, viene de Kardex sincronizado con Restaurant cada
     * 30 min) con los overrides manuales de `local_activo_overrides` para
     * los casos en que el automático no calza con la realidad (un local
     * recién reabierto que todavía no acumula 3 días de venta, o un cierre
     * reciente que Kardex aún no refleja). Un override manda siempre,
     * sea cual sea el resultado automático para ese local.
     *
     * Investigado antes de programar: Restaurant expone `local_estado` y
     * `local_esventa` por local (`obtenerLocalesPermitidosParaUsuarioID`),
     * pero probado en vivo contra los 37 locales reales, los 5 locales ya
     * confirmados como cerrados siguen marcados `estado=1, es_venta=1` --
     * es una config de catálogo (¿puede este local vender en el POS?), no
     * un indicador de operación real, y nadie la actualiza cuando un local
     * cierra. No sirve como fuente de verdad por eso NO se usa acá.
     *
     * @param  array<int, string>  $localesIds
     * @return array<int, string>
     */
    public function localesActivos(array $localesIds): array
    {
        if ($localesIds === []) {
            return [];
        }

        $automaticos = array_flip($this->localesConVentaActiva($localesIds));
        $overrides = LocalActivoOverride::whereIn('local_id', $localesIds)->get()->keyBy('local_id');

        $resultado = [];
        foreach ($localesIds as $id) {
            $override = $overrides->get($id);
            $activo = $override ? (bool) $override->activo : isset($automaticos[$id]);
            if ($activo) {
                $resultado[] = $id;
            }
        }

        return $resultado;
    }

    /**
     * Última venta real de un ítem de despacho por local, para mostrar en
     * "Locales Activos" -- misma ventana/universo que `localesConVentaActiva()`
     * pero sin cortar a 3 días, para poder mostrar "hace cuántos días" sin
     * límite (una tienda cerrada hace 4 meses también tiene que aparecer).
     *
     * @param  array<int, string>  $localesIds
     * @return array<string, ?\Illuminate\Support\Carbon> local_id => última fecha_hora de venta, o null si nunca vendió un ítem de despacho
     */
    public function ultimaVentaDespachoPorLocal(array $localesIds): array
    {
        if ($localesIds === []) {
            return [];
        }

        $productos = ProductoPresentacionDespacho::get(['item_id', 'item_tipo']);
        if ($productos->isEmpty()) {
            return array_fill_keys($localesIds, null);
        }

        $ultimas = DB::table('kardex_movimientos')
            ->where('almacen', self::ALMACEN)
            ->where('motivo', self::MOTIVO_VENTA)
            ->whereIn('local_id', $localesIds)
            ->where(function ($query) use ($productos): void {
                foreach ($productos as $producto) {
                    $query->orWhere(fn ($q) => $q->where('item_id', $producto->item_id)->where('tipo_item', $producto->item_tipo));
                }
            })
            ->selectRaw('local_id, max(fecha_hora) as ultima')
            ->groupBy('local_id')
            ->pluck('ultima', 'local_id');

        $resultado = [];
        foreach ($localesIds as $id) {
            $fecha = $ultimas->get($id);
            $resultado[$id] = $fecha ? Carbon::parse($fecha) : null;
        }

        return $resultado;
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
     * Primer día DESPUÉS de `$desde` que tiene llegada real de transporte
     * para este local -- un día "D" tiene llegada si y solo si el día
     * anterior (D-1) NO es un día sin DT (si D-1 no generó DT, no se
     * despachó nada para que llegue en D). Se camina día por día en vez de
     * sumar un número fijo, para que "saltar" 1 o más días sin DT
     * seguidos se resuelva solo, sea cual sea el día de la semana.
     *
     * Ejemplo real (sábado = día sin DT para un local): calculando un
     * viernes, proximaLlegada(viernes) = sábado (viernes sí generó DT).
     * proximaLlegada(sábado) salta el domingo (sábado no generó DT, así
     * que domingo no tiene llegada) y da lunes -- el despacho del viernes
     * termina cubriendo sábado Y domingo.
     *
     * @param  array<int, int>  $diasSinDt
     *
     * @throws \RuntimeException  si el local tiene los 7 días de la semana
     *                             marcados como "sin DT" -- una
     *                             configuración sin sentido (nunca
     *                             recibiría reposición) que de otro modo
     *                             dejaría este método en un bucle infinito.
     */
    private function proximaLlegada(Carbon $desde, array $diasSinDt): Carbon
    {
        $dia = $desde->copy()->addDay();
        $intentos = 0;
        while (in_array($dia->copy()->subDay()->dayOfWeekIso, $diasSinDt, true)) {
            $dia->addDay();
            if (++$intentos > 14) {
                throw new \RuntimeException('Un local tiene los 7 días de la semana marcados como "sin DT" -- revisa la configuración en Días sin DT, ningún local puede quedar así.');
            }
        }

        return $dia;
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

        return (string) ($config?->hora_llegada_estimada ?? self::HORA_POR_DEFECTO);
    }
}
