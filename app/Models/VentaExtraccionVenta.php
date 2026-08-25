<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VentaExtraccionVenta extends Model
{
    protected $table = 'venta_extraccion_ventas';

    protected $fillable = ['extraccion_id', 'venta_id', 'estado', 'resumen'];

    protected function casts(): array
    {
        return ['resumen' => 'array'];
    }
}
