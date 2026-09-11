<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductoComercialRecetaManual extends Model
{
    protected $table = 'productos_comerciales_recetas_manuales';

    protected $fillable = [
        'producto_restaurant_id', 'vigente_desde', 'observacion', 'registrado_por',
    ];

    protected function casts(): array
    {
        return ['vigente_desde' => 'date'];
    }

    public function componentes(): HasMany
    {
        return $this->hasMany(ProductoComercialRecetaManualComponente::class, 'receta_manual_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
