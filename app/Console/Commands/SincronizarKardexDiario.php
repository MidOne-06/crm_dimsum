<?php

namespace App\Console\Commands;

use App\Jobs\ExtraerKardexJob;
use App\Models\KardexExtraccion;
use App\Services\KardexGatewayClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * A diferencia de Ventas/Guías internas/Salidas de stock/Requerimientos,
 * Kardex no tenía NINGUNA extracción automática programada -- dependía
 * 100% de que un usuario entrara a Kardex > Extracción y presionara el
 * botón a mano. Encontrado en la auditoría de módulos del 2026-09-03: la
 * última corrida real llevaba 2 días de antigüedad. Este comando cierra
 * ese hueco extrayendo el día anterior (ya cerrado/estable en Restaurant,
 * a diferencia de "hoy" que sigue cambiando) para todos los locales, una
 * vez al día.
 */
class SincronizarKardexDiario extends Command
{
    protected $signature = 'kardex:sincronizar-diario {--fecha= : Día a extraer (Y-m-d). Por defecto, ayer.}';

    protected $description = 'Crea y despacha automáticamente la extracción diaria de Kardex para todos los locales.';

    public function handle(KardexGatewayClient $gateway): int
    {
        // Mismo criterio que el botón manual (KardexExtraccion::hayExtraccionEnProgreso):
        // nunca dos extracciones activas a la vez, para no competir por la
        // única sesión de navegador que sostiene kardex-worker (1 réplica,
        // a propósito -- ver compose.yaml).
        if (KardexExtraccion::query()->whereIn('estado', ['pendiente', 'en_progreso'])->exists()) {
            $this->info('Ya hay una extracción de Kardex activa; se reintentará en el próximo ciclo.');

            return self::SUCCESS;
        }

        $fecha = (string) ($this->option('fecha') ?: now()->subDay()->toDateString());

        // Idempotencia: si el día ya se extrajo con éxito (para todos los
        // locales conocidos hoy), no lo repite -- evita volver a descargar
        // 37 reportes xlsx si el scheduler corre más de una vez sobre la
        // misma fecha (redeploy, reinicio).
        $yaExtraido = KardexExtraccion::query()
            ->where('estado', 'completado')
            ->whereJsonContains('filtros->fechaInicio', $fecha)
            ->whereJsonContains('filtros->fechaFin', $fecha)
            ->exists();

        if ($yaExtraido) {
            $this->info("Kardex del {$fecha} ya fue extraído; nada que hacer.");

            return self::SUCCESS;
        }

        try {
            $locales = $gateway->locals();
        } catch (Throwable $exception) {
            $this->error('No se pudo obtener la lista de locales del gateway: '.$exception->getMessage());

            return self::FAILURE;
        }

        $localesIds = collect($locales)->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();
        if ($localesIds === []) {
            $this->error('El gateway no devolvió ningún local.');

            return self::FAILURE;
        }

        $filtros = [
            'locales' => implode('-', $localesIds),
            'localesNombres' => collect($locales)->mapWithKeys(fn (array $l): array => [(string) $l['id'] => (string) ($l['name'] ?? '')])->all(),
            'motivo' => '-1',
            'fechaInicio' => $fecha,
            'fechaFin' => $fecha,
        ];

        $extraccion = KardexExtraccion::create([
            'estado' => 'pendiente',
            'filtros' => $filtros,
            'iniciado_por' => null,
        ]);

        ExtraerKardexJob::dispatch($extraccion->id)->onQueue('kardex');
        $this->info("Kardex del {$fecha}: extracción #{$extraccion->id} despachada para ".count($localesIds).' locales.');

        return self::SUCCESS;
    }
}
