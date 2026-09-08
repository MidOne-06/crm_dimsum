<?php

namespace App\Jobs;

use App\Models\CanjeMasivo;
use App\Services\GuiasInternasGatewayClient;
use App\Services\MovimientosAlmacenesGatewayClient;
use DateTime;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Fase 2, la que sí escribe: registra un movimiento real por cada grupo
 * compatible de cada tanda de hasta 20 guías. Usa los valores que Restaurant
 * ya trae por defecto para cada grupo (fecha, encargado, almacén de
 * destino, tipo de movimiento) -- corrida automática, sin un humano
 * revisando cada pestaña, así que NUNCA edita cantidades ni ningún otro
 * campo, para no inventar un dato donde no hay nadie mirando la pantalla.
 * Si una tanda falla, se registra el fallo y se sigue con las demás -- no
 * tiene sentido que un lote problemático bloquee el resto de guías
 * genuinamente válidas.
 */
class ConfirmarCanjeMasivoJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 7200;

    public function __construct(public int $canjeMasivoId)
    {
        $this->onQueue('movimientos-almacenes');
    }

    public function retryUntil(): DateTime
    {
        return now()->addHours(4);
    }

    public function handle(MovimientosAlmacenesGatewayClient $movimientos, GuiasInternasGatewayClient $guias): void
    {
        $canje = CanjeMasivo::find($this->canjeMasivoId);
        if (! $canje || $canje->estado !== 'confirmando') {
            return;
        }

        // Cerrojo por corrida -- encontrado en falta EN VIVO, con la
        // primera corrida real del usuario (611 guías) ya en marcha: la
        // cola usa el driver 'database', que vuelve a ofrecer un job a
        // CUALQUIER worker si su `reserved_at` supera DB_QUEUE_RETRY_AFTER
        // (780s = 13 min) -- SIN IMPORTAR si el worker original lo sigue
        // procesando. Una corrida de cientos de guías tarda bastante más
        // que eso, así que sin este lock un segundo worker podría tomar el
        // mismo job y confirmar las mismas guías dos veces (dos
        // movimientos reales por la misma guía). Mismo patrón que
        // SincronizarMovimientosAlmacenesJob, pero por-corrida (no global)
        // porque acá cada CanjeMasivo opera sobre su propia lista de ids.
        $lock = Cache::lock("canje-masivo:{$this->canjeMasivoId}", $this->timeout + 60);
        if (! $lock->get()) {
            $this->release(30);

            return;
        }

        try {
            $this->confirmar($canje, $movimientos, $guias);
        } finally {
            $lock->release();
        }
    }

    private function confirmar(CanjeMasivo $canje, MovimientosAlmacenesGatewayClient $movimientos, GuiasInternasGatewayClient $guias): void
    {
        $ids = array_values(array_map('strval', (array) ($canje->resultado['ids_procesables'] ?? [])));
        $confirmadas = 0;
        $fallidas = 0;
        // Un job puede ser reentregado después de un corte de worker.
        // Restaurant rechaza una guía ya recepcionada, pero el historial
        // local no debe repetir la evidencia del mismo movimiento.
        $movimientosCreados = $this->normalizarMovimientosAuditados((array) ($canje->resultado['movimientos_creados'] ?? []));
        $fallos = [];

        foreach (array_chunk($ids, 20) as $lote) {
            // Punto de cancelación real, entre tandas -- pedido explícito
            // del usuario tras tener que cortar una corrida real matando el
            // worker a mano (sin esto, "Detener" en la pantalla no tenía
            // ningún efecto sobre un job ya en curso, solo sobre uno que
            // todavía no había arrancado). Se revisa ACÁ, antes de lanzar la
            // siguiente tanda, nunca a mitad de una ya en vuelo -- una vez
            // disparada, esa tanda de 20 sigue su curso hasta el final (ver
            // por qué en el catch de abajo: Restaurant la sigue procesando
            // igual aunque el cliente HTTP se rinda, así que cortarla a la
            // fuerza mid-tanda solo generaría el mismo hueco de contabilidad
            // que esto viene a cerrar).
            if ($canje->fresh()->estado !== 'confirmando') {
                return;
            }

            try {
                // Se vuelve a leer Restaurant justo antes de escribir --
                // mismo principio que el canje manual: nunca se confía en
                // un estado leído hace rato, puede haber cambiado.
                $preparado = $movimientos->prepararCanjeGuiasMasivo($lote);
                $grupos = array_map(fn (array $grupo): array => [
                    'clave' => (string) ($grupo['clave'] ?? ''),
                    'ids' => (array) ($grupo['ids'] ?? []),
                    'fecha' => (string) ($grupo['fecha'] ?? ''),
                    'encargado' => (string) ($grupo['encargado'] ?? ''),
                    'receptor' => (string) ($grupo['receptor'] ?? ''),
                    'almacen_destino' => (string) ($grupo['almacenDestino']['id'] ?? ''),
                    'tipo_movimiento' => (string) ($grupo['tipoMovimiento'] ?? ''),
                    'observacion' => (string) ($grupo['observacion'] ?? ''),
                ], (array) ($preparado['groups'] ?? []));

                $resultado = $movimientos->canjearGuiasMasivo(['ids' => $lote, 'grupos' => $grupos, 'confirmar' => true]);

                foreach ((array) ($resultado['results'] ?? []) as $fila) {
                    $guiasDelGrupo = (array) ($fila['ids'] ?? []);
                    if ($fila['ok'] ?? false) {
                        $confirmadas += count($guiasDelGrupo);
                        $movimientosCreados = $this->agregarMovimientoAuditado($movimientosCreados, [
                            'movimiento_id' => $fila['id'] ?? null,
                            'guias' => $guiasDelGrupo,
                        ]);
                    } else {
                        $fallidas += count($guiasDelGrupo);
                        $fallos[] = ['guias' => $guiasDelGrupo, 'error' => (string) ($fila['error'] ?? 'Restaurant rechazó este grupo.')];
                    }
                }
            } catch (Throwable $exception) {
                // NO asumir que la tanda entera falló solo porque el cliente
                // HTTP se rindió -- hallazgo real de la corrida del
                // 2026-09-08 (611 guías): Restaurant procesa cada guía en
                // secuencia y puede tardar más que cualquier timeout
                // razonable del lado de Laravel, pero SIGUE trabajando la
                // petición igual aunque el cliente ya haya cortado la
                // espera (confirmado en los logs del gateway: los lotes que
                // "fallaron" por cURL error 28 llegaron limpio hasta
                // "registro del movimiento", sin ningún error real, y esas
                // guías ya no aparecían como pendientes al verificar
                // después). Reportar esos lotes como "fallidos" sin
                // verificar habría sido un dato falso -- y peor, un
                // reintento futuro sobre una guía que en realidad ya se
                // había confirmado sí generaría un movimiento duplicado de
                // verdad. Antes de contar cualquier guía de este lote como
                // fallida, se verifica su estado real en Restaurant.
                [$confirmadasVerificadas, $fallidasReales] = $this->reconciliarLote($lote, $guias);
                $confirmadas += count($confirmadasVerificadas);
                foreach ($confirmadasVerificadas as $guia) {
                    $movimientosCreados = $this->agregarMovimientoAuditado($movimientosCreados, [
                        'movimiento_id' => $guia['movimiento_id'] !== '' ? $guia['movimiento_id'] : null,
                        'guias' => [$guia['id']],
                        'nota' => 'Verificado post-error del cliente HTTP ("'.$exception->getMessage().'"): la guía quedó recepcionada en Restaurant pese al error.',
                    ]);
                }
                $fallidas += count($fallidasReales);
                if ($fallidasReales !== []) {
                    $fallos[] = ['guias' => $fallidasReales, 'error' => $exception->getMessage()];
                }
            }

            // Avance incremental persistido tras cada tanda -- si esta
            // corrida se corta a mitad de camino, lo ya confirmado queda
            // visible y auditable, no se pierde en un solo update final.
            $canje->update([
                'total_guias_confirmadas' => $confirmadas,
                'total_guias_fallidas' => $fallidas,
                'total_movimientos_creados' => count($movimientosCreados),
                'resultado' => [...((array) $canje->resultado), 'movimientos_creados' => $movimientosCreados, 'fallos' => $fallos],
            ]);
        }

        if ($canje->fresh()->estado === 'confirmando') {
            $canje->update([
                'estado' => $fallidas > 0 ? 'completado_con_errores' : 'completado',
                'completado_en' => now(),
            ]);
        }
    }

    /**
     * Verifica contra Restaurant, guía por guía, si de verdad quedó
     * recepcionada -- se usa SOLO cuando el cliente HTTP no pudo confirmar
     * la respuesta del lote completo, nunca para reemplazar el resultado
     * real de Restaurant cuando sí llegó. Si ni siquiera se puede verificar
     * una guía puntual (Restaurant también inalcanzable en ese momento), se
     * cuenta como fallida de verdad -- más vale subreportar que inventar un
     * éxito.
     *
     * OJO: el campo `recepcionada` de nivel superior que trae
     * GuiasInternasGatewayClient::detalle() (heredado de mapRow(), pensado
     * para las filas del LISTADO) siempre viene vacío acá -- confirmado en
     * vivo reconciliando la corrida real del 2026-09-08: el detalle de una
     * guía puntual no lo llena, solo el listado lo hace. El dato real (y de
     * paso el `movimiento_id` real, que el flujo normal ni siquiera
     * conoce) vive en `sourceData.guiaremision.guiaremision_recepcionada`.
     *
     * @param  array<int, string>  $lote
     * @return array{0: array<int, array{id: string, movimiento_id: string}>, 1: array<int, string>}
     */
    private function reconciliarLote(array $lote, GuiasInternasGatewayClient $guias): array
    {
        $confirmadas = [];
        $fallidas = [];
        foreach ($lote as $id) {
            try {
                $detalle = $guias->detalle($id);
                $guia = (array) ($detalle['sourceData']['guiaremision'] ?? []);
                $recepcionada = trim((string) ($guia['guiaremision_recepcionada'] ?? ''));
                if ($recepcionada === '1') {
                    $confirmadas[] = ['id' => $id, 'movimiento_id' => (string) ($guia['movimiento_id'] ?? '')];
                } else {
                    $fallidas[] = $id;
                }
            } catch (Throwable) {
                $fallidas[] = $id;
            }
        }

        return [$confirmadas, $fallidas];
    }

    /** @param array<int, array<string, mixed>> $movimientos */
    private function normalizarMovimientosAuditados(array $movimientos): array
    {
        $unicos = [];
        foreach ($movimientos as $movimiento) {
            if (! is_array($movimiento)) {
                continue;
            }

            $unicos[$this->claveMovimientoAuditado($movimiento)] ??= $movimiento;
        }

        return array_values($unicos);
    }

    /** @param array<int, array<string, mixed>> $movimientos @param array<string, mixed> $nuevo */
    private function agregarMovimientoAuditado(array $movimientos, array $nuevo): array
    {
        $clave = $this->claveMovimientoAuditado($nuevo);
        foreach ($movimientos as $movimiento) {
            if (is_array($movimiento) && $this->claveMovimientoAuditado($movimiento) === $clave) {
                return $movimientos;
            }
        }

        $movimientos[] = $nuevo;

        return $movimientos;
    }

    /** @param array<string, mixed> $movimiento */
    private function claveMovimientoAuditado(array $movimiento): string
    {
        $movimientoId = trim((string) ($movimiento['movimiento_id'] ?? ''));
        $guias = array_values(array_unique(array_filter(array_map('strval', (array) ($movimiento['guias'] ?? [])))));
        sort($guias, SORT_NATURAL);

        return $movimientoId !== ''
            ? 'movimiento:'.$movimientoId
            : 'guias:'.implode(',', $guias);
    }
}
