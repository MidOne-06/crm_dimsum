<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MovimientoAlmacenDetalle extends Model
{
    use SoftDeletes;

    protected $table = 'movimientos_almacenes_detalles';

    protected $fillable = [
        'movimiento_id', 'restaurant_id', 'item_id', 'codigo', 'item', 'presentacion', 'unidad',
        'cantidad', 'almacen_origen', 'almacen_destino', 'valorizado', 'estado', 'payload_restaurant',
    ];

    protected function casts(): array
    {
        return ['cantidad' => 'decimal:4', 'valorizado' => 'decimal:4', 'payload_restaurant' => 'array'];
    }

    public function movimiento(): BelongsTo
    {
        return $this->belongsTo(MovimientoAlmacenHistorico::class, 'movimiento_id');
    }
}
