<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Excepción de hora de llegada por local + día de la semana -- ver docblock
 * de la migración. Un local sin ninguna fila acá usa
 * LocalLogisticaConfig::hora_llegada_estimada (o 12:00 por defecto) para
 * los 7 días.
 */
class LocalLogisticaHorario extends Model
{
    protected $table = 'local_logistica_horarios';

    protected $fillable = ['local_id', 'dia_semana', 'hora'];

    protected function casts(): array
    {
        return ['dia_semana' => 'integer'];
    }

    public const DIAS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
}
