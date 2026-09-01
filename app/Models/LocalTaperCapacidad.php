<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Máximo de tapers de un tipo dado que soporta la congeladora de un local. */
class LocalTaperCapacidad extends Model
{
    protected $table = 'local_taper_capacidades';

    protected $fillable = ['local_id', 'local_nombre', 'taper_tipo_id', 'capacidad_maxima'];

    public function taperTipo(): BelongsTo
    {
        return $this->belongsTo(TaperTipo::class);
    }
}
