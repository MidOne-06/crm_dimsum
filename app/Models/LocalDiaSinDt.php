<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Día de la semana en que un local NO genera Directiva de Transferencia --
 * ver docblock de la migración y DirectivaTransferenciaService.
 */
class LocalDiaSinDt extends Model
{
    protected $table = 'local_dias_sin_dt';

    protected $fillable = ['local_id', 'dia_semana'];

    protected function casts(): array
    {
        return ['dia_semana' => 'integer'];
    }

    public const DIAS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
}
