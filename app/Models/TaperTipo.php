<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaperTipo extends Model
{
    protected $fillable = ['nombre', 'descripcion'];

    public function productos(): HasMany
    {
        return $this->hasMany(ProductoTaper::class);
    }

    public function localCapacidades(): HasMany
    {
        return $this->hasMany(LocalTaperCapacidad::class);
    }
}
