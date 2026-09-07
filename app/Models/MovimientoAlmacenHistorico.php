<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MovimientoAlmacenHistorico extends Model
{
    use SoftDeletes;

    protected $table = 'movimientos_almacenes_historico';

    protected $fillable = [
        'sincronizacion_id', 'restaurant_id', 'fecha', 'local_origen_id', 'local_origen',
        'almacen_origen_id', 'almacen_origen', 'local_destino_id', 'local_destino',
        'almacen_destino_id', 'almacen_destino', 'encargado', 'receptor', 'registrado_por',
        'total_items', 'valorizado', 'estado_codigo', 'estado', 'estado_recepcion_codigo',
        'estado_recepcion', 'observacion', 'payload_restaurant', 'sincronizado_en',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'datetime', 'valorizado' => 'decimal:4', 'payload_restaurant' => 'array',
            'sincronizado_en' => 'datetime',
        ];
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(MovimientoAlmacenDetalle::class, 'movimiento_id');
    }

    public function sincronizacion(): BelongsTo
    {
        return $this->belongsTo(MovimientoAlmacenSincronizacion::class, 'sincronizacion_id');
    }
}
