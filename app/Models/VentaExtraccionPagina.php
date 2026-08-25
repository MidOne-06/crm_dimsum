<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VentaExtraccionPagina extends Model
{
    protected $table = 'venta_extraccion_paginas';

    protected $fillable = ['extraccion_id', 'pagina', 'estado'];
}
