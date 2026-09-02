<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Cantidad de arranque estándar por producto -- vía "estándar" del modo de arranque, y usable para producto nuevo sin historial en ningún local. */
class CantidadEstandarArranque extends Model
{
    protected $fillable = ['item_id', 'item_codigo', 'item_nombre', 'cantidad_arranque'];
}
