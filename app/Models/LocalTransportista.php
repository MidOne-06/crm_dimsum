<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Titular o suplente de un local para las entregas de despacho. El índice
 * único (local_id, es_suplente) garantiza exactamente 1 titular + 1
 * suplente por local. Ver migración y RegistrarEntrega.
 */
class LocalTransportista extends Model
{
    protected $table = 'local_transportistas';

    protected $fillable = ['local_id', 'local_nombre', 'user_id', 'es_suplente'];

    protected function casts(): array
    {
        return ['es_suplente' => 'boolean'];
    }

    public function transportista(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
