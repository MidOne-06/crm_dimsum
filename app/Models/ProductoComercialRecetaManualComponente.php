<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductoComercialRecetaManualComponente extends Model
{
    protected $table = 'productos_comerciales_recetas_manuales_componentes';

    protected $fillable = [
        'receta_manual_id', 'componente_restaurant_producto_id', 'cantidad_por_producto',
    ];

    protected function casts(): array
    {
        return ['cantidad_por_producto' => 'decimal:6'];
    }

    public function receta(): BelongsTo
    {
        return $this->belongsTo(ProductoComercialRecetaManual::class, 'receta_manual_id');
    }
}
