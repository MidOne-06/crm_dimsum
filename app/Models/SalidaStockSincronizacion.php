<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalidaStockSincronizacion extends Model
{
    protected $table = 'salidas_stock_sincronizaciones';

    protected $fillable = [
        'fecha_inicio', 'fecha_fin', 'estado', 'paginas_total', 'paginas_procesadas',
        'cabeceras_guardadas', 'detalles_guardados', 'cabeceras_eliminadas', 'errores',
        'mensaje_error', 'iniciado_en', 'completado_en',
    ];

    protected function casts(): array
    {
        return ['fecha_inicio' => 'date', 'fecha_fin' => 'date', 'iniciado_en' => 'datetime', 'completado_en' => 'datetime'];
    }

    public function salidas(): HasMany
    {
        return $this->hasMany(SalidaStock::class, 'sincronizacion_id');
    }
}
