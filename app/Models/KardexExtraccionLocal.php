<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KardexExtraccionLocal extends Model
{
    protected $table = 'kardex_extraccion_locales';

    protected $fillable = [
        'extraccion_id',
        'local_id',
        'local_nombre',
        'estado',
        'movimientos_guardados',
        'mensaje_error',
    ];

    public function extraccion(): BelongsTo
    {
        return $this->belongsTo(KardexExtraccion::class, 'extraccion_id');
    }
}
