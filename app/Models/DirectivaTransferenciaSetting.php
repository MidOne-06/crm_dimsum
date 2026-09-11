<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Nivel de servicio del stock de seguridad de la Directiva de Transferencia
 * -- fila única (mismo patrón que `BrandingSetting`), editable desde
 * "Stock de seguridad" (Configuración) en vez de vivir hardcodeado en el
 * modelo `DirectivaTransferenciaSugerencia` como antes. `factor_servicio`
 * es el que de verdad usa `DirectivaTransferenciaService` -- `nivel_servicio_pct`
 * es solo para mostrarlo en el formulario (90/95/98%), no participa en el
 * cálculo directamente.
 */
class DirectivaTransferenciaSetting extends Model
{
    protected $table = 'directiva_transferencia_settings';

    protected $fillable = ['nivel_servicio_pct', 'factor_servicio', 'actualizado_por'];

    protected function casts(): array
    {
        return [
            'nivel_servicio_pct' => 'integer',
            'factor_servicio' => 'decimal:4',
        ];
    }

    /**
     * Nivel de servicio -> factor z (distribución normal estándar inversa).
     * Como string a propósito: asignar un float a un campo `decimal:N` de
     * Eloquent dispara un deprecation real de `brick/math` (ver bitácora
     * 2026-09-11) -- pasar el string exacto evita la conversión float->
     * BigNumber por dentro.
     */
    public const NIVELES_DISPONIBLES = [
        90 => '1.2816',
        95 => '1.6449',
        98 => '2.0537',
    ];

    public static function current(): self
    {
        return static::query()->find(1) ?? new static([
            'nivel_servicio_pct' => 95,
            'factor_servicio' => self::NIVELES_DISPONIBLES[95],
        ]);
    }

    public function factorServicio(): float
    {
        return (float) $this->factor_servicio;
    }
}
