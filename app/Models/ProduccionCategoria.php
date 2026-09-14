<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProduccionCategoria extends Model
{
    protected $table = 'produccion_categorias';

    protected $fillable = ['nombre', 'orden'];

    public function productos(): HasMany
    {
        return $this->hasMany(ProduccionProducto::class, 'produccion_categoria_id');
    }
}
