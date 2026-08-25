<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpmProductoCandidato extends Model
{
    protected $table = 'opm_producto_candidatos';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id', 'producto_id', 'ejecucion_id', 'consulta_normalizada', 'nombre_producto', 'nombre_normalizado',
        'concentracion', 'forma', 'grupo', 'cod_grupo_ff',
    ];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(OpmProducto::class, 'producto_id');
    }
}
