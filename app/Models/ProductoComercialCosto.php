<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductoComercialCosto extends Model
{
    protected $table = 'productos_comerciales_costos';

    protected $fillable = [
        'producto_restaurant_id', 'costo_unitario', 'vigente_desde', 'observacion', 'registrado_por',
    ];

    protected function casts(): array
    {
        return [
            'costo_unitario' => 'decimal:6',
            'vigente_desde' => 'date',
        ];
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
