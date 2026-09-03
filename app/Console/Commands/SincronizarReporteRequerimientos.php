<?php

namespace App\Console\Commands;

use App\Models\RequerimientoStockSincronizacion;
use App\Services\RequerimientoStockGatewayClient;
use App\Services\RequerimientoStockHistoricoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SincronizarReporteRequerimientos extends Command
{
    // --desde/--hasta/--locales solo aplican cuando NO se pasa --sync-id: sin
    // esto, el módulo de requerimientos no tenía forma de programarse (a
    // diferencia de guías/salidas/stock actual) -- dependía por completo de
    // que un usuario abriera el reporte y presionara "Sincronizar filtro" a
    // mano, así que el reporte podía quedar días sin datos frescos sin que
    // nadie lo notara.
    protected $signature = 'requerimientos-stock:sincronizar-reporte {--sync-id=} {--desde=} {--hasta=} {--locales=*}';

    protected $description = 'Sincroniza en segundo plano el reporte de requerimientos desde Restaurant.';

    public function handle(RequerimientoStockGatewayClient $gateway, RequerimientoStockHistoricoService $historico): int
    {
        if (filled($this->option('sync-id'))) {
            $run = RequerimientoStockSincronizacion::find((int) $this->option('sync-id'));
            if (! $run || $run->estado !== 'pendiente') {
                return self::SUCCESS;
            }

            return $this->sincronizar($run, $gateway, $historico);
        }

        // Modo "crear y correr" (uso desde el scheduler): una sola fila,
        // protegida contra solapes igual que los otros 3 módulos.
        $lock = Cache::lock('requerimientos-stock:sync', 14400);
        if (! $lock->get()) {
            $this->info('Ya hay una sincronización de Requerimientos de Stock en curso.');

            return self::SUCCESS;
        }

        try {
            $locales = array_values(array_filter((array) $this->option('locales')));
            $run = RequerimientoStockSincronizacion::create([
                'filtros' => [
                    'fecha_inicio' => (string) ($this->option('desde') ?: now()->subDays(3)->toDateString()),
                    'fecha_fin' => (string) ($this->option('hasta') ?: now()->toDateString()),
                    'locales' => $locales, 'locales_produccion' => [],
                    'estado' => '-1', 'codigo' => '', 'encargado' => '', 'por_fecha' => '0', 'items' => [],
                ],
                'estado' => 'pendiente',
            ]);

            return $this->sincronizar($run, $gateway, $historico);
        } finally {
            $lock->release();
        }
    }

    /** Público para que SincronizarRequerimientosStockJob (colas) lo reutilice sin duplicar la lógica. */
    public function sincronizar(RequerimientoStockSincronizacion $run, RequerimientoStockGatewayClient $gateway, RequerimientoStockHistoricoService $historico): int
    {

        $filters = (array) $run->filtros;
        $page = 1;
        $processed = 0;
        $saved = 0;
        $details = 0;
        $failed = 0;
        $errors = [];

        // NO se registra proceso_pid: esto corre en el worker compartido de
        // colas (contenedor `worker`), no en un proceso propio -- guardar
        // getmypid() aquí apuntaría al PID del worker entero, y "Detener" en
        // la UI lo mataría junto con cualquier otra cola que esté procesando
        // (ventas, stock actual). "Detener" solo marca 'cancelado'; el
        // chequeo de abajo entre páginas es lo que realmente para el trabajo.
        $run->update(['estado' => 'en_progreso', 'iniciado_en' => now(), 'mensaje_error' => null]);
        $paginasFallidas = [];
        $cancelado = false;

        try {
            do {
                if (RequerimientoStockSincronizacion::query()->whereKey($run->id)->value('estado') === 'cancelado') { $cancelado = true; break; }
                try {
                    $result = $gateway->lista($filters + ['pagina' => $page, 'registros' => 100]);
                } catch (Throwable $exception) {
                    // Página irrecuperable tras los reintentos del gateway
                    // (ver RequerimientoStockGatewayClient::get): se registra
                    // el hueco y se para aquí -- a diferencia de guías/salidas,
                    // aquí no conocemos $total todavía si falla la página 1,
                    // y si falla una intermedia no hay forma de saber cuántas
                    // páginas más habría sin ella. Queda 'completado_con_errores'
                    // y una re-sincronización posterior retoma desde el total
                    // ya conocido.
                    $paginasFallidas[] = $page;
                    $errors[] = "Página {$page}: {$exception->getMessage()}";
                    break;
                }
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

            if ($cancelado) {
                return self::SUCCESS;
            }

            $run->update([
                'estado' => ($failed === 0 && $paginasFallidas === []) ? 'completado' : 'completado_con_errores',
                'registros_procesados' => $processed,
                'cabeceras_guardadas' => $saved,
                'detalles_guardados' => $details,
                'errores' => $failed + count($paginasFallidas),
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
