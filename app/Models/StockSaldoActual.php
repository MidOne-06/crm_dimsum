<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Saldo materializado por local x ítem (clave `item_id`+`item_tipo`, NUNCA
 * `item_id` solo -- ver comentario en la migración de
 * stock_iniciales_detalles). Se recalcula por completo en cada corrida de
 * StockSaldoRecalculadorService, nunca a mano.
 */
class StockSaldoActual extends Model
{
    protected $table = 'stock_saldos_actuales';

    protected $fillable = [
        'local_id', 'local_nombre', 'item_id', 'item_tipo', 'item_codigo', 'item_nombre',
        'unidad', 'cantidad_inicial', 'ajustes_acumulados', 'entradas_kardex', 'salidas_kardex',
        'saldo', 'kardex_actualizado_hasta', 'recalculado_en',
    ];

    protected function casts(): array
    {
        return [
            'cantidad_inicial' => 'decimal:4',
            'ajustes_acumulados' => 'decimal:4',
            'entradas_kardex' => 'decimal:4',
            'salidas_kardex' => 'decimal:4',
            'saldo' => 'decimal:4',
            'kardex_actualizado_hasta' => 'datetime',
            'recalculado_en' => 'datetime',
        ];
    }

    /** Sin extracción de Kardex reciente para este local -- el saldo puede no reflejar la realidad. */
    public function estaDesactualizado(int $horasUmbral = 48): bool
    {
        return $this->kardex_actualizado_hasta === null
            || $this->kardex_actualizado_hasta->lt(now()->subHours($horasUmbral));
    }
}
