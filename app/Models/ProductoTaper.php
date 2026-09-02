<?php

namespace App\Models;

use App\Models\Concerns\RegistraTrazabilidad;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cuántas unidades de un producto caben en un tipo de taper dado. */
class ProductoTaper extends Model
{
    use RegistraTrazabilidad;

    protected $fillable = ['item_id', 'item_codigo', 'item_nombre', 'taper_tipo_id', 'capacidad_unidades'];

    public function taperTipo(): BelongsTo
    {
        return $this->belongsTo(TaperTipo::class);
    }
}
