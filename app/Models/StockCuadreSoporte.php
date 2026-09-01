<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockCuadreSoporte extends Model
{
    protected $table = 'stock_cuadre_soportes';

    protected $fillable = ['estado', 'filtros', 'paginas_total', 'paginas_procesadas', 'cuadres_guardados', 'detalles_guardados', 'mensaje_error', 'iniciado_at', 'completado_at'];

    protected function casts(): array
    {
        return ['filtros' => 'array', 'iniciado_at' => 'datetime', 'completado_at' => 'datetime'];
    }

    public function cuadres(): HasMany
    {
        return $this->hasMany(StockCuadre::class, 'soporte_id');
    }
}
