<?php

namespace App\Console\Commands;

use App\Models\DirectivaSaldoDiarioHistorico;
use App\Models\ProductoPresentacionDespacho;
use App\Models\StockInicialLocal;
use App\Models\StockSaldoActual;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Guarda una foto del saldo de cierre de AYER (o de `--fecha` si se pasa)
 * para cada local confirmado x producto de despacho activo -- la pieza que
 * faltaba para poder corregir el sesgo ya documentado del promedio
 * histórico de la Directiva de Transferencia (ver docblock de la migración
 * `create_directiva_saldo_diario_historicos_table`).
 *
 * Programado a las 02:50 (10 min antes de `directiva-transferencia:calcular`,
 * que corre a las 03:00) -- a esa hora Kardex ya sincronizó todo el día que
 * acaba de cerrar (los syncs corren cada 30 min, sin cortes, y entre las
 * 00:00 y las 02:50 no hay venta real que distorsione el corte), así que
 * `stock_saldos_actuales.saldo` en ese momento es, en la práctica, el saldo
 * de cierre real de AYER, no de hoy (que recién empezó hace unas horas). No
 * se recalcula nada acá -- se lee tal cual está, la misma fuente que ya usa
 * el resto de la Directiva.
 */
class CapturarSaldoDiarioDirectiva extends Command
{
    protected $signature = 'directiva-transferencia:capturar-saldo-diario {--fecha= : Fecha a capturar (Y-m-d). Por defecto, ayer.}';

    protected $description = 'Guarda el saldo de cierre del día para cada local x producto de despacho, para detectar días de quiebre en el promedio histórico de la Directiva.';

    public function handle(): int
    {
        $fecha = Carbon::parse((string) ($this->option('fecha') ?: now()->subDay()->toDateString()))->toDateString();

        $productos = ProductoPresentacionDespacho::where('activo', true)->get();
        if ($productos->isEmpty()) {
            $this->info('Sin productos activos de despacho -- nada que capturar.');

            return self::SUCCESS;
        }

        $locales = StockInicialLocal::where('estado', 'confirmado')->get(['local_id', 'local_nombre']);
        if ($locales->isEmpty()) {
            $this->info('Sin locales confirmados -- nada que capturar.');

            return self::SUCCESS;
        }

        $itemIds = $productos->pluck('item_id')->unique()->all();
        $ahora = now();
        $filas = [];

        foreach ($locales as $local) {
            $saldos = StockSaldoActual::where('local_id', $local->local_id)
                ->whereIn('item_id', $itemIds)
                ->get()
                ->keyBy(fn (StockSaldoActual $s): string => "{$s->item_id}|{$s->item_tipo}");

            foreach ($productos as $producto) {
                $saldo = $saldos->get("{$producto->item_id}|{$producto->item_tipo}");
                $saldoCierre = (float) ($saldo?->saldo ?? 0);

                $filas[] = [
                    'fecha' => $fecha,
                    'local_id' => $local->local_id,
                    'local_nombre' => $local->local_nombre,
                    'item_id' => $producto->item_id,
                    'item_tipo' => $producto->item_tipo,
                    'item_codigo' => $producto->item_codigo,
                    'item_nombre' => $producto->item_nombre,
                    'saldo_cierre' => $saldoCierre,
                    'quiebre' => $saldoCierre <= 0,
                    'capturado_en' => $ahora,
                    'updated_at' => $ahora,
                    'created_at' => $ahora,
                ];
            }
        }

        foreach (array_chunk($filas, 500) as $lote) {
            DirectivaSaldoDiarioHistorico::upsert(
                $lote,
                ['fecha', 'local_id', 'item_id', 'item_tipo'],
                ['local_nombre', 'item_codigo', 'item_nombre', 'saldo_cierre', 'quiebre', 'capturado_en', 'updated_at'],
            );
        }

        $quiebres = collect($filas)->where('quiebre', true)->count();
        $this->info("Capturadas {$fecha}: ".count($filas)." filas ({$quiebres} en quiebre).");

        return self::SUCCESS;
    }
}
