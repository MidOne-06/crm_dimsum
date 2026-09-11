<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductoComercialComponente extends Model
{
    protected $table = 'productos_comerciales_componentes';

    protected $fillable = [
        'composicion_id', 'componente_restaurant_producto_id', 'nombre', 'descripcion_venta',
        'codigo', 'unidad', 'cantidad_por_producto', 'fuente_restaurant',
    ];

    protected function casts(): array
    {
        return [
            'cantidad_por_producto' => 'decimal:6',
            'fuente_restaurant' => 'array',
        ];
    }

    public function composicion(): BelongsTo
    {
        return $this->belongsTo(ProductoComercialComposicion::class, 'composicion_id');
    }
}
