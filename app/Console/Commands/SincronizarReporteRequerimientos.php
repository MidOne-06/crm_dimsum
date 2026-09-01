<?php

namespace App\Console\Commands;

use App\Models\RequerimientoStockSincronizacion;
use App\Services\RequerimientoStockGatewayClient;
use App\Services\RequerimientoStockHistoricoService;
use Illuminate\Console\Command;
use Throwable;

class SincronizarReporteRequerimientos extends Command
{
    protected $signature = 'requerimientos-stock:sincronizar-reporte {--sync-id=}';

    protected $description = 'Sincroniza en segundo plano el reporte de requerimientos desde Restaurant.';

    public function handle(RequerimientoStockGatewayClient $gateway, RequerimientoStockHistoricoService $historico): int
    {
        $run = RequerimientoStockSincronizacion::find((int) $this->option('sync-id'));
        if (! $run || $run->estado !== 'pendiente') {
            return self::SUCCESS;
        }

        $filters = (array) $run->filtros;
        $page = 1;
        $processed = 0;
        $saved = 0;
        $details = 0;
        $failed = 0;
        $errors = [];

        $run->update(['estado' => 'en_progreso', 'iniciado_en' => now(), 'mensaje_error' => null]);

        try {
            do {
                $result = $gateway->lista($filters + ['pagina' => $page, 'registros' => 100]);
                $total = (int) ($result['total'] ?? 0);
                $run->update(['total_registros' => $total]);

                foreach ($result['rows'] ?? [] as $row) {
                    $id = (string) ($row['codigo'] ?? '');
                    if (! ctype_digit($id)) {
                        continue;
                    }

                    try {
                        $detail = $gateway->detalle($id);
                        $historico->sincronizar($detail);
                        $saved++;
                        $details += count($detail['detalles'] ?? []);
                    } catch (Throwable $exception) {
                        $failed++;
                        $errors[] = "Requerimiento {$id}: {$exception->getMessage()}";
                    }

                    $processed++;
                    $run->update([
                        'registros_procesados' => $processed,
                        'cabeceras_guardadas' => $saved,
                        'detalles_guardados' => $details,
                        'errores' => $failed,
                    ]);
                }

                $page++;
            } while (($page - 1) * 100 < ($run->total_registros ?? 0));

            $run->update([
                'estado' => $failed === 0 ? 'completado' : 'completado_con_errores',
                'registros_procesados' => $processed,
                'cabeceras_guardadas' => $saved,
                'detalles_guardados' => $details,
                'errores' => $failed,
                'mensaje_error' => $errors === [] ? null : implode("\n", array_slice($errors, 0, 20)),
                'completado_en' => now(),
            ]);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $run->update(['estado' => 'fallido', 'mensaje_error' => $exception->getMessage(), 'completado_en' => now()]);
            report($exception);

            return self::FAILURE;
        }
    }
}
