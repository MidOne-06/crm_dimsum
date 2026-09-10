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
 * botón a mano. Encontrado en la auditoría de módulos del 2026-09-03.
 *
 * Rediseño del 2026-09-10 (pedido explícito del usuario tras auditar
 * Aurora): antes corría UNA vez al día extrayendo SOLO el día anterior, y
 * tenía una guarda de idempotencia que se saltaba la corrida si ya existía
 * cualquier extracción "completada" de esa fecha. Eso rompía el cierre del
 * día: si alguien presionaba "Sincronizar y calcular" a media tarde (que
 * extrae "hoy"), esa corrida marcaba la fecha como "extraída" y la nocturna
 * NUNCA volvía a traerla -- todas las ventas/entradas posteriores a esa hora
 * quedaban fuera del CRM hasta que alguien re-extrajera a mano (hueco real de
 * 26 unidades encontrado en Aurora / SM001 el 2026-09-10).
 *
 * Ahora: se ejecuta CADA 30 MINUTOS como el resto de los módulos del Panel de
 * Sincronización, extrae una ventana corta (ayer + hoy, con solape para
 * capturar correcciones tardías de Restaurant y cerrar la cola de cada día),
 * y SIN guarda de idempotencia -- `ProcesarLocalKardexJob::reemplazar()` borra
 * e inserta el rango por local, así que repetir la misma fecha es idempotente
 * y su único costo es ~2 min de un worker. La única guarda que queda es la de
 * "no dos extracciones activas a la vez" (1 réplica de kardex-worker).
 */
class SincronizarKardexDiario extends Command
{
    protected $signature = 'kardex:sincronizar-diario
        {--fecha= : Última fecha a extraer (Y-m-d). Por defecto, hoy.}
        {--desde= : Primera fecha a extraer (Y-m-d). Por defecto, ayer.}';

    protected $description = 'Crea y despacha la extracción incremental de Kardex (ayer + hoy) para todos los locales.';

    public function handle(KardexGatewayClient $gateway): int
    {
        // Mismo criterio que el botón manual (KardexExtraccion::hayExtraccionEnProgreso):
        // nunca dos extracciones activas a la vez, para no competir por la
        // única sesión de navegador que sostiene kardex-worker (1 réplica,
        // a propósito -- ver compose.yaml). Si hay una en curso (manual o del
        // ciclo anterior), esta corrida se saltea y el scheduler reintenta en
        // el próximo tick de 30 min.
        if (KardexExtraccion::query()->whereIn('estado', ['pendiente', 'en_progreso'])->exists()) {
            $this->info('Ya hay una extracción de Kardex activa; se reintentará en el próximo ciclo.');

            return self::SUCCESS;
        }

        $fechaFin = (string) ($this->option('fecha') ?: now()->toDateString());
        $fechaInicio = (string) ($this->option('desde') ?: now()->subDay()->toDateString());

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
            'fechaInicio' => $fechaInicio,
            'fechaFin' => $fechaFin,
        ];

        $extraccion = KardexExtraccion::create([
            'estado' => 'pendiente',
            'filtros' => $filtros,
            'iniciado_por' => null,
        ]);

        ExtraerKardexJob::dispatch($extraccion->id)->onQueue('kardex');
        $this->info("Kardex {$fechaInicio}..{$fechaFin}: extracción #{$extraccion->id} despachada para ".count($localesIds).' locales.');

        return self::SUCCESS;
    }
}
