<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductoComercialComposicion extends Model
{
    protected $table = 'productos_comerciales_composiciones';

    protected $fillable = [
        'producto_restaurant_id', 'huella', 'primera_venta_en', 'ultima_venta_en', 'fuente_restaurant',
    ];

    protected function casts(): array
    {
        return [
            'primera_venta_en' => 'datetime',
            'ultima_venta_en' => 'datetime',
            'fuente_restaurant' => 'array',
        ];
    }

    public function componentes(): HasMany
    {
        return $this->hasMany(ProductoComercialComponente::class, 'composicion_id');
    }
}
