<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VentaDetalle extends Model
{
    protected $table = 'venta_detalles';

    protected $fillable = [
        'venta_id',
        'item_id',
        'descripcion',
        'cantidad',
        'precio',
        'descuento',
        'importe',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:4',
            'precio' => 'decimal:4',
            'descuento' => 'decimal:2',
            'importe' => 'decimal:2',
        ];
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'venta_id', 'venta_id');
    }
}
