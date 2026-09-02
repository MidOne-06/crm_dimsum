<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Días de vida útil de un producto -- acota cuánto tiene sentido enviar aunque haya demanda y espacio de sobra. */
class ProductoVidaUtil extends Model
{
    protected $fillable = ['item_id', 'item_codigo', 'item_nombre', 'dias_vida_util'];
}
