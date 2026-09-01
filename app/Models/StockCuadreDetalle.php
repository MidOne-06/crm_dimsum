<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCuadreDetalle extends Model
{
    protected $table = 'stock_cuadre_detalles';

    protected $fillable = ['stock_cuadre_id', 'restaurant_id', 'item_id', 'item_codigo', 'item', 'tipo', 'almacen_id', 'almacen', 'unidad', 'aumento', 'disminucion', 'costo', 'impuestos', 'total', 'stock_anterior', 'stock_actual', 'valorizacion', 'activo', 'payload_restaurant'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'payload_restaurant' => 'array'];
    }

    public function cuadre(): BelongsTo { return $this->belongsTo(StockCuadre::class, 'stock_cuadre_id'); }
}
