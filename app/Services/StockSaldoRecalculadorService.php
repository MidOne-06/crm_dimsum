<?php

namespace App\Services;

use App\Models\KardexExtraccionLocal;
use App\Models\StockInicialLocal;
use App\Models\StockSaldoActual;
use Illuminate\Support\Facades\DB;

/**
 * Recalcula el saldo en tiempo real de un local, SIEMPRE desde cero (nunca
 * de forma incremental): saldo = cantidad_inicial + ajustes + SUM(entrada)
 * - SUM(salida) de Kardex desde la fecha de carga. Esto es deliberado -- si
 * alguna vez hay que re-extraer Kardex por un error retroactivo (ya pasó
 * varias veces en este proyecto: timezone, jobs huérfanos, duplicados), el
 * saldo se autocorrige solo en la siguiente corrida, sin arrastrar ningún
 * error de una corrida anterior.
 *
 * Clave de ítem SIEMPRE item_id + item_tipo, nunca item_id solo -- ver
 * comentario en la migración de stock_iniciales_detalles: item_id se
 * reutiliza en Restaurant para productos distintos según tipo_item.
 *
 * Alcance: solo "Almacen Principal" -- es el único almacén real de los 36
 * locales que reciben transferencias. FABRICA (con sus 4 sub-almacenes de
 * materia prima/producción) queda fuera a propósito: es el productor, no
 * un nodo de la red de reposición (mismo criterio que DRP -- Distribution
 * Requirements Planning -- usa para separar planta de red de distribución).
 */
class StockSaldoRecalculadorService
{
    private const ALMACEN = 'Almacen Principal';

    public function recalcularLocal(string $localId): int
    {
        $cabecera = StockInicialLocal::query()
            ->where('local_id', $localId)
            ->where('estado', 'confirmado')
            ->first();

        // Sin carga inicial confirmada todavía: no hay nada que recalcular
        // para este local. No es un error -- el local simplemente no
        // arrancó su stock inicial todavía.
        if (! $cabecera) {
            return 0;
        }

        $fechaCarga = $cabecera->fecha_carga->toDateString();

        /** @var array<string, array{cantidad_inicial: float, item_codigo: ?string, item_nombre: ?string, unidad: ?string}> $base */
        $base = $cabecera->detalles()->get()->keyBy(fn ($d) => $this->clave($d->item_id, $d->item_tipo))
            ->map(fn ($d) => [
                'cantidad_inicial' => (float) $d->cantidad_inicial,
                'item_codigo' => $d->item_codigo,
                'item_nombre' => $d->item_nombre,
                'unidad' => $d->unidad,
            ])->all();

        /** @var array<string, float> $ajustes */
        $ajustes = DB::table('stock_iniciales_ajustes')
            ->where('local_id', $localId)
            ->selectRaw('item_id, item_tipo, SUM(cantidad_ajuste) AS total')
            ->groupBy('item_id', 'item_tipo')
            ->get()
            ->mapWithKeys(fn ($row) => [$this->clave($row->item_id, $row->item_tipo) => (float) $row->total])
            ->all();

        /** @var \Illuminate\Support\Collection $kardex */
        $kardex = DB::table('kardex_movimientos')
            ->where('local_id', $localId)
            ->where('almacen', self::ALMACEN)
            ->whereDate('fecha', '>=', $fechaCarga)
            // OJO: la columna real en kardex_movimientos es `tipo_item`, no
            // `item_tipo` -- están al revés respecto al resto de este
            // módulo (que sí usa item_tipo, nombre propio de sus tablas).
            // Un `select item_tipo` acá directamente rompe con "column does
            // not exist" (encontrado en vivo la primera vez que se probó
            // esta pantalla).
            ->selectRaw('item_id, tipo_item AS item_tipo, MAX(item_nombre) AS item_nombre, MAX(unidad_medida) AS unidad,
                SUM(entrada) AS entradas, SUM(salida) AS salidas')
            ->groupBy('item_id', 'tipo_item')
            ->get();

        $ultimaExtraccion = KardexExtraccionLocal::query()
            ->where('local_id', $localId)
            ->where('estado', 'completado')
            ->max('completado_at');

        $claves = array_unique([
            ...array_keys($base),
            ...array_keys($ajustes),
            ...$kardex->map(fn ($row) => $this->clave($row->item_id, $row->item_tipo))->all(),
        ]);

        $filas = [];
        $ahora = now();

        foreach ($claves as $clave) {
            [$itemId, $itemTipo] = $this->desclave($clave);
            $movimiento = $kardex->first(fn ($row) => $this->clave($row->item_id, $row->item_tipo) === $clave);

            $cantidadInicial = $base[$clave]['cantidad_inicial'] ?? 0.0;
            $ajusteAcumulado = $ajustes[$clave] ?? 0.0;
            $entradas = $movimiento ? (float) $movimiento->entradas : 0.0;
            $salidas = $movimiento ? (float) $movimiento->salidas : 0.0;

            $filas[] = [
                'local_id' => $localId,
                'local_nombre' => $cabecera->local_nombre,
                'item_id' => $itemId,
                'item_tipo' => $itemTipo,
                'item_codigo' => $base[$clave]['item_codigo'] ?? null,
                'item_nombre' => $movimiento->item_nombre ?? ($base[$clave]['item_nombre'] ?? null),
                'unidad' => $movimiento->unidad ?? ($base[$clave]['unidad'] ?? null),
                'cantidad_inicial' => $cantidadInicial,
                'ajustes_acumulados' => $ajusteAcumulado,
                'entradas_kardex' => $entradas,
                'salidas_kardex' => $salidas,
                'saldo' => $cantidadInicial + $ajusteAcumulado + $entradas - $salidas,
                'kardex_actualizado_hasta' => $ultimaExtraccion,
                'recalculado_en' => $ahora,
                'updated_at' => $ahora,
                'created_at' => $ahora,
            ];
        }

        foreach (array_chunk($filas, 500) as $lote) {
            StockSaldoActual::query()->upsert(
                $lote,
                ['local_id', 'item_id', 'item_tipo'],
                ['local_nombre', 'item_codigo', 'item_nombre', 'unidad', 'cantidad_inicial', 'ajustes_acumulados',
                    'entradas_kardex', 'salidas_kardex', 'saldo', 'kardex_actualizado_hasta', 'recalculado_en', 'updated_at'],
            );
        }

        return count($filas);
    }

    private function clave(?string $itemId, ?string $itemTipo): string
    {
        return trim((string) $itemId).'|'.trim((string) $itemTipo);
    }

    /** @return array{0: string, 1: ?string} */
    private function desclave(string $clave): array
    {
        [$itemId, $itemTipo] = array_pad(explode('|', $clave, 2), 2, null);

        return [$itemId, $itemTipo === '' ? null : $itemTipo];
    }
}
