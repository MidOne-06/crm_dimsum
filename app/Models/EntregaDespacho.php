<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una entrega de despacho marcada por un transportista. Control puramente
 * operativo -- no toca guías internas ni `recepcionada`. Sirve para el
 * histórico y el promedio de hora de entrega por local x día de semana.
 */
class EntregaDespacho extends Model
{
    protected $table = 'entregas_despacho';

    protected $fillable = [
        'user_id', 'local_id', 'local_nombre', 'fecha_hora', 'dia_semana',
        'rol_entrega', 'es_reemplazo', 'motivo_reemplazo', 'foto_path', 'observacion',
    ];

    protected function casts(): array
    {
        return [
            'fecha_hora' => 'datetime',
            'es_reemplazo' => 'boolean',
            'dia_semana' => 'integer',
        ];
    }

    public const DIAS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

    public function transportista(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
