<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpmProductoAlias extends Model
{
    protected $table = 'opm_producto_aliases';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id', 'producto_id', 'parametro_id', 'ejecucion_id',
        'nombre_catalogo', 'nombre_catalogo_normalizado',
        'principio_activo', 'principio_activo_normalizado',
        'codigo_catalogo', 'registro_sanitario', 'presentacion',
        'fabricante', 'titular', 'combinacion_key',
    ];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(OpmProducto::class, 'producto_id');
    }
}
