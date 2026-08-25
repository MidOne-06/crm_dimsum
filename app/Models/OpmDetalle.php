<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpmDetalle extends Model
{
    protected $table = 'opm_detalles';
    protected $primaryKey = 'detail_key';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'detail_key', 'cod_estab', 'cod_prod_e',
        'precio1', 'precio2',
        'nombre_producto', 'pais_fabricacion', 'registro_sanitario',
        'condicion_venta', 'tipo_producto', 'nombre_titular', 'nombre_fabricante',
        'presentacion', 'laboratorio', 'director_tecnico', 'nombre_comercial',
        'telefono', 'email', 'ruc', 'direccion',
        'departamento', 'provincia', 'distrito',
        'horario_atencion', 'ubigeo', 'cat_codigo',
    ];

    public function precio(): BelongsTo
    {
        return $this->belongsTo(OpmPrecio::class, 'detail_key', 'id');
    }
}
