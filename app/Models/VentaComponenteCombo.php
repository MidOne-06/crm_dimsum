<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VentaComponenteCombo extends Model
{
    protected $table = 'venta_componentes_combo';

    protected $fillable = [
        'venta_id',
        'detalle_venta_item_id',
        'producto_compuesto_restaurant_id',
        'producto_restaurant_id',
        'descripcion',
        'unidad',
        'cantidad_por_combo',
        'cantidad_total',
        'composicion_comercial_id',
        'origen',
    ];

    protected function casts(): array
    {
        return [
            'cantidad_por_combo' => 'decimal:6',
            'cantidad_total' => 'decimal:6',
        ];
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'venta_id', 'venta_id');
    }

    public function composicion(): BelongsTo
    {
        return $this->belongsTo(ProductoComercialComposicion::class, 'composicion_comercial_id');
    }
}
