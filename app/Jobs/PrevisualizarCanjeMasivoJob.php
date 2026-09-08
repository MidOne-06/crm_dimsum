<?php

namespace App\Jobs;

use App\Models\CanjeMasivo;
use App\Services\GuiasInternasGatewayClient;
use DateTime;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Fase 1 de "canjear todo lo filtrado": SOLO LEE Restaurant, nunca escribe
 * nada. Deja armado el resumen (cuántas guías, cuántos grupos/movimientos
 * resultantes, cuánto suman) para que el usuario decida si confirma o no --
 * ConfirmarCanjeMasivoJob es el único que de verdad escribe.
 *
 * OJO, rediseño real tras probarlo en producción: la primera versión
 * llamaba a prepararCanjeGuiasMasivo() en tandas de 20 SOLO para contar
 * cuántos movimientos resultarían -- pero esa llamada re-hidrata cada guía
 * una por una contra Restaurant (secuencial a propósito, según el propio
 * gateway). Con un filtro real de 611 guías eso tardó más de 20 minutos y
 * countdown/pool de sesiones de Restaurant.pe se fue liberando por
 * inactividad -- inutilizable como "vista previa". La clave de
 * agrupamiento real (`guideExchangeGroupKey()` en el gateway) es
 * `{localId}:{almacenOrigenId}:{localDestinoLocalId}` -- exactamente los
 * campos que el LISTADO YA TRAE por fila (`localOrigenId`, `almacenId`,
 * `localDestinoId`), sin ninguna llamada extra. Se calcula la misma clave
 * acá, en memoria, sobre las filas ya descargadas -- vista previa en
 * segundos en vez de minutos, mismo resultado.
 */
class PrevisualizarCanjeMasivoJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public function __construct(public int $canjeMasivoId)
    {
        $this->onQueue('movimientos-almacenes');
    }

    public function retryUntil(): DateTime
    {
        return now()->addHours(2);
    }

    public function handle(GuiasInternasGatewayClient $guias): void
    {
        $canje = CanjeMasivo::find($this->canjeMasivoId);
        if (! $canje || $canje->estado !== 'previsualizando') {
            return;
        }

        // Mismo cerrojo por corrida que ConfirmarCanjeMasivoJob -- ver su
        // docblock. Este job ya es rápido (segundos, no minutos), pero un
        // filtro que devuelva miles de guías igual podría acercarse al
        // umbral de DB_QUEUE_RETRY_AFTER; más vale prevenir.
        $lock = Cache::lock("canje-masivo-preview:{$this->canjeMasivoId}", $this->timeout + 60);
        if (! $lock->get()) {
            $this->release(30);

            return;
        }

        try {
            $this->previsualizar($canje, $guias);
        } finally {
            $lock->release();
        }
    }

    private function previsualizar(CanjeMasivo $canje, GuiasInternasGatewayClient $guias): void
    {
        try {
            $filas = $this->recolectarTodasLasPaginas($guias, (array) $canje->filtros);

            // Mismo criterio que assertGuidesPending() del gateway: estado
            // activo (código '1') y todavía no recepcionada. Filtrar acá
            // primero evita mandar a Restaurant guías que igual rechazaría,
            // y deja un conteo de "excluidas" explicable en el resumen.
            $excluidas = [];
            $procesables = [];
            $valorizadoTotal = 0.0;
            $clavesGrupo = [];
            foreach ($filas as $fila) {
                $id = (string) ($fila['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $estadoCodigo = (string) ($fila['estadoCodigo'] ?? '');
                $recepcionada = strtoupper(trim((string) ($fila['recepcionada'] ?? '')));
                if ($estadoCodigo !== '1' || in_array($recepcionada, ['SI', 'SÍ', 'TRUE', 'RECEPCIONADA'], true)) {
                    $excluidas[] = ['id' => $id, 'motivo' => $estadoCodigo !== '1' ? 'No está activa ('.($fila['estado'] ?? $estadoCodigo).').' : 'Ya fue recepcionada.'];

                    continue;
                }
                $procesables[] = $id;
                $valorizadoTotal += (float) ($fila['total'] ?? 0);

                // Misma fórmula que guideExchangeGroupKey() en el gateway,
                // calculada acá con los campos que el listado ya trae --
                // ver el docblock de la clase.
                $clave = (string) ($fila['localOrigenId'] ?? '').':'.(string) ($fila['almacenId'] ?? '').':'.(string) ($fila['localDestinoId'] ?? '');
                $clavesGrupo[$clave] = true;
            }

            $canje->update([
                'estado' => 'listo',
                'total_guias_filtro' => count($filas),
                'total_guias_procesables' => count($procesables),
                'total_guias_excluidas' => count($excluidas),
                'total_grupos_estimados' => count($clavesGrupo),
                'total_valorizado_estimado' => $valorizadoTotal,
                'resultado' => ['excluidas' => $excluidas, 'fallos_preview' => [], 'ids_procesables' => $procesables],
                'previsualizado_en' => now(),
            ]);
        } catch (Throwable $exception) {
            $canje->update(['estado' => 'fallido', 'mensaje_error' => $exception->getMessage()]);
        }
    }

    /**
     * Pagina TODO el listado que coincide con el filtro -- no solo la
     * primera página que muestra la grilla. registros=100 por página para
     * no multiplicar de más las consultas contra Restaurant.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recolectarTodasLasPaginas(GuiasInternasGatewayClient $guias, array $filtros): array
    {
        $filas = [];
        $pagina = 1;
        $registrosPorPagina = 100;

        do {
            $resultado = $guias->guias([...$filtros, 'pagina' => (string) $pagina, 'registros' => (string) $registrosPorPagina]);
            $rows = (array) ($resultado['rows'] ?? []);
            $filas = [...$filas, ...$rows];
            $pagina++;
        } while (count($rows) === $registrosPorPagina && $pagina <= 200); // 200 páginas = 20.000 guías, tope de seguridad contra un bucle infinito real

        return $filas;
    }
}
