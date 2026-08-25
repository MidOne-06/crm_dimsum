<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KardexMovimiento extends Model
{
    protected $table = 'kardex_movimientos';

    protected $fillable = [
        'extraccion_local_id',
        'local_id',
        'local_nombre',
        'almacen',
        'categoria',
        'tipo_item',
        'item_id',
        'item_nombre',
        'fecha',
        'hora',
        'fecha_hora',
        'motivo',
        'observacion',
        'doc_entidad',
        'entidad',
        'unidad_medida',
        'entrada',
        'salida',
        'stock',
        'costo_unitario',
        'costo_promedio',
        'costo_movimiento',
        'costo_operacion',
        'stock_valorizado',
        'canal_venta',
        'id_producto_venta',
        'cod_interno',
        'producto',
        'tienda',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'fecha_hora' => 'datetime',
            'entrada' => 'decimal:3',
            'salida' => 'decimal:3',
            'stock' => 'decimal:3',
            'costo_unitario' => 'decimal:4',
            'costo_promedio' => 'decimal:4',
            'costo_movimiento' => 'decimal:4',
            'costo_operacion' => 'decimal:4',
            'stock_valorizado' => 'decimal:4',
        ];
    }
}
