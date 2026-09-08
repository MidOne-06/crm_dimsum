<?php

namespace App\Jobs;

use App\Models\CanjeMasivo;
use App\Services\GuiasInternasGatewayClient;
use App\Services\MovimientosAlmacenesGatewayClient;
use DateTime;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Fase 1 de "canjear todo lo filtrado": SOLO LEE Restaurant, nunca escribe
 * nada -- ni prepararCanjeGuiasMasivo (llamado acá) ni el listado de guías
 * registran movimientos, por diseño del propio gateway (ver su docblock:
 * "No registra ni modifica movimientos en esta etapa"). Deja armado el
 * resumen (cuántas guías, cuántos grupos/movimientos resultantes, cuánto
 * suman) para que el usuario decida si confirma o no -- ConfirmarCanjeMasivoJob
 * es el único que de verdad escribe.
 */
class PrevisualizarCanjeMasivoJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public function __construct(public int $canjeMasivoId)
    {
        $this->onQueue('movimientos-almacenes');
    }

    public function retryUntil(): DateTime
    {
        return now()->addHours(2);
    }

    public function handle(GuiasInternasGatewayClient $guias, MovimientosAlmacenesGatewayClient $movimientos): void
    {
        $canje = CanjeMasivo::find($this->canjeMasivoId);
        if (! $canje || $canje->estado !== 'previsualizando') {
            return;
        }

        try {
            $filas = $this->recolectarTodasLasPaginas($guias, (array) $canje->filtros);

            // Mismo criterio que assertGuidesPending() del gateway: estado
            // activo (código '1') y todavía no recepcionada. Filtrar acá
            // primero evita mandar a Restaurant guías que igual rechazaría,
            // y deja un conteo de "excluidas" explicable en el resumen.
            $excluidas = [];
            $procesables = [];
            $valorizadoTotal = 0.0;
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
            }

            // Se re-agrupa en tandas de 20 (el máximo real que admite
            // Restaurant) SOLO para saber cuántos movimientos resultarían y
            // detectar de una vez cualquier lote que Restaurant rechazaría
            // -- prepararCanjeGuiasMasivo no escribe nada, es información.
            $totalGrupos = 0;
            $fallosPreview = [];
            foreach (array_chunk($procesables, 20) as $lote) {
                try {
                    $preparado = $movimientos->prepararCanjeGuiasMasivo($lote);
                    $totalGrupos += count($preparado['groups'] ?? []);
                } catch (Throwable $exception) {
                    $fallosPreview[] = ['ids' => $lote, 'error' => $exception->getMessage()];
                }
            }

            $canje->update([
                'estado' => 'listo',
                'total_guias_filtro' => count($filas),
                'total_guias_procesables' => count($procesables),
                'total_guias_excluidas' => count($excluidas),
                'total_grupos_estimados' => $totalGrupos,
                'total_valorizado_estimado' => $valorizadoTotal,
                'resultado' => ['excluidas' => $excluidas, 'fallos_preview' => $fallosPreview, 'ids_procesables' => $procesables],
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
