<?php

namespace App\Jobs;

use App\Models\CanjeMasivo;
use App\Services\MovimientosAlmacenesGatewayClient;
use DateTime;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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

    public function handle(MovimientosAlmacenesGatewayClient $movimientos): void
    {
        $canje = CanjeMasivo::find($this->canjeMasivoId);
        if (! $canje || $canje->estado !== 'confirmando') {
            return;
        }

        $ids = array_values(array_map('strval', (array) ($canje->resultado['ids_procesables'] ?? [])));
        $confirmadas = 0;
        $fallidas = 0;
        $movimientosCreados = [];
        $fallos = [];

        foreach (array_chunk($ids, 20) as $lote) {
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
                        $movimientosCreados[] = ['movimiento_id' => $fila['id'] ?? null, 'guias' => $guiasDelGrupo];
                    } else {
                        $fallidas += count($guiasDelGrupo);
                        $fallos[] = ['guias' => $guiasDelGrupo, 'error' => (string) ($fila['error'] ?? 'Restaurant rechazó este grupo.')];
                    }
                }
            } catch (Throwable $exception) {
                $fallidas += count($lote);
                $fallos[] = ['guias' => $lote, 'error' => $exception->getMessage()];
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

        $canje->update([
            'estado' => $fallidas > 0 ? 'completado_con_errores' : 'completado',
            'completado_en' => now(),
        ]);
    }
}
