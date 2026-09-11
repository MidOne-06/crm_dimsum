<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ver docblock de la migración `2026_09_12_090000_...` -- registra la
 * intención de calcular la Directiva (ajuste %, alcance) apenas se piden
 * las sincronizaciones de Kardex/Guías, para que `CalcularDirectivaTrasSincronizacionJob`
 * pueda terminar el trabajo server-side aunque el navegador que lo pidió
 * ya no esté.
 */
class DirectivaTransferenciaSolicitud extends Model
{
    protected $table = 'directiva_transferencia_solicitudes';

    protected $fillable = [
        'fecha_referencia', 'kardex_extraccion_id', 'guia_sincronizacion_id',
        'porcentaje_ajuste_global', 'porcentaje_ajuste_por_local',
        'estado', 'mensaje_error', 'resultado', 'iniciado_por', 'completado_en',
    ];

    protected function casts(): array
    {
        return [
            'fecha_referencia' => 'date',
            'porcentaje_ajuste_global' => 'decimal:2',
            'porcentaje_ajuste_por_local' => 'array',
            'resultado' => 'array',
            'completado_en' => 'datetime',
        ];
    }

    public function terminada(): bool
    {
        return in_array($this->estado, ['calculado', 'fallido', 'cancelado'], true);
    }
}
