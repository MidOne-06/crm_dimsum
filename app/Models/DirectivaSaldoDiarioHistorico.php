<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Foto del saldo de cierre de cada día por local x producto de despacho --
 * ver docblock de la migración para el porqué existe. Alimentada solo por
 * `directiva-transferencia:capturar-saldo-diario` (programado a las 02:50,
 * antes del cálculo de la Directiva a las 03:00); nada más debe escribir
 * acá.
 */
class DirectivaSaldoDiarioHistorico extends Model
{
    protected $table = 'directiva_saldo_diario_historicos';

    protected $fillable = [
        'fecha', 'local_id', 'local_nombre', 'item_id', 'item_tipo',
        'item_codigo', 'item_nombre', 'saldo_cierre', 'quiebre', 'capturado_en',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'saldo_cierre' => 'decimal:4',
            'quiebre' => 'boolean',
            'capturado_en' => 'datetime',
        ];
    }
}
