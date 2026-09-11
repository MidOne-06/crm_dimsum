<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DirectivaTransferenciaSugerencia extends Model
{
    protected $table = 'directiva_transferencia_sugerencias';

    protected $fillable = [
        'fecha_despacho', 'dia_semana', 'local_id', 'local_nombre', 'item_id', 'item_tipo',
        'item_codigo', 'item_nombre', 'demanda_promedio', 'demanda_ventana1', 'desviacion_estandar', 'riesgo_quiebre',
        'semanas_consideradas', 'saldo_actual', 'cantidad_en_transito', 'cantidad_bruta',
        'porcentaje_ajuste_aplicado', 'cantidad_bruta_ajustada',
        'multiplo_aplicado', 'cantidad_sugerida', 'calculado_en',
    ];

    /** Nivel de servicio ~95% (elegido por el usuario) -- ver docblock de la migración de desviacion_estandar. */
    public const FACTOR_SERVICIO_95 = 1.65;

    /** Colchón real que la fórmula sumó por variabilidad, antes del redondeo. */
    public function stockSeguridad(): float
    {
        return self::FACTOR_SERVICIO_95 * (float) $this->desviacion_estandar;
    }

    protected function casts(): array
    {
        return [
            'fecha_despacho' => 'date',
            'demanda_promedio' => 'decimal:4',
            'demanda_ventana1' => 'decimal:4',
            'desviacion_estandar' => 'decimal:4',
            'riesgo_quiebre' => 'boolean',
            'saldo_actual' => 'decimal:4',
            'cantidad_en_transito' => 'decimal:4',
            'cantidad_bruta' => 'decimal:4',
            'porcentaje_ajuste_aplicado' => 'decimal:2',
            'cantidad_bruta_ajustada' => 'decimal:4',
            'calculado_en' => 'datetime',
        ];
    }

    /** Menos de 3 semanas de histórico real detrás del promedio -- confiar poco en la sugerencia. */
    public function esConfianzaBaja(): bool
    {
        return $this->semanas_consideradas < 3;
    }
}
