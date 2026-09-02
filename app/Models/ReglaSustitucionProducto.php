<?php

namespace App\Models;

use App\Models\Concerns\RegistraTrazabilidad;
use Illuminate\Database\Eloquent\Model;

/** Producto sustituto válido cuando falta la presentación exacta en origen (ej. taper de 120 agotado, hay de 50). */
class ReglaSustitucionProducto extends Model
{
    use RegistraTrazabilidad;

    protected $fillable = ['item_original_id', 'item_original_nombre', 'item_sustituto_id', 'item_sustituto_nombre', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }
}
