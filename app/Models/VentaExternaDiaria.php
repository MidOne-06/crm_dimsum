<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VentaExternaDiaria extends Model
{
    protected $table = 'ventas_externas_diarias';

    protected $fillable = [
        'canal_id', 'fecha', 'venta_sin_igv', 'igv', 'venta_con_igv', 'tickets', 'costo_sin_igv',
        'observacion', 'motivo_anulacion', 'estado', 'registrado_por', 'actualizado_por', 'anulado_en', 'anulado_por',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'venta_sin_igv' => 'decimal:2',
            'igv' => 'decimal:2',
            'venta_con_igv' => 'decimal:2',
            'costo_sin_igv' => 'decimal:2',
            'anulado_en' => 'datetime',
        ];
    }

    public function canal(): BelongsTo
    {
        return $this->belongsTo(CanalVentaExterna::class, 'canal_id');
    }

    public function auditorias(): HasMany
    {
        return $this->hasMany(VentaExternaAuditoria::class, 'venta_externa_diaria_id');
    }
}
