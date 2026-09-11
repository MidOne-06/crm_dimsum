<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductoComercialRestaurant extends Model
{
    protected $table = 'productos_comerciales_restaurant';

    protected $fillable = [
        'restaurant_producto_id', 'nombre', 'descripcion_venta', 'codigo', 'unidad',
        'es_combo', 'lleva_ingredientes', 'tiene_composicion_restaurant',
        'fuente_restaurant', 'sincronizado_en',
    ];

    protected function casts(): array
    {
        return [
            'es_combo' => 'boolean',
            'lleva_ingredientes' => 'boolean',
            'tiene_composicion_restaurant' => 'boolean',
            'fuente_restaurant' => 'array',
            'sincronizado_en' => 'datetime',
        ];
    }

    public function composiciones(): HasMany
    {
        return $this->hasMany(ProductoComercialComposicion::class, 'producto_restaurant_id', 'restaurant_producto_id');
    }
}
