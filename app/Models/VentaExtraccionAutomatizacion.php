<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VentaExtraccionAutomatizacion extends Model
{
    protected $table = 'venta_extraccion_automatizaciones';

    protected $fillable = [
        'estado',
        'segmentos',
        'indice_actual',
        'extraccion_actual_id',
        'iniciado_por',
        'mensaje_error',
    ];

    protected function casts(): array
    {
        return ['segmentos' => 'array'];
    }

    public function extraccionActual(): BelongsTo
    {
        return $this->belongsTo(VentaExtraccion::class, 'extraccion_actual_id');
    }
}
