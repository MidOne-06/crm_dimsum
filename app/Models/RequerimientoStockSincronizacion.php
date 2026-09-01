<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequerimientoStockSincronizacion extends Model
{
    protected $table = 'requerimientos_stock_sincronizaciones';

    protected $fillable = [
        'filtros', 'estado', 'iniciado_por', 'total_registros', 'registros_procesados',
        'cabeceras_guardadas', 'detalles_guardados', 'errores', 'mensaje_error',
        'iniciado_en', 'completado_en',
    ];

    protected function casts(): array
    {
        return [
            'filtros' => 'array',
            'iniciado_en' => 'datetime',
            'completado_en' => 'datetime',
        ];
    }
}
