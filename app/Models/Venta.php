<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Venta extends Model
{
    protected $table = 'ventas';

    protected $primaryKey = 'venta_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'venta_id',
        'venta_fecha',
        'local_id',
        'local',
        'cliente_id',
        'cliente',
        'cliente_ruc',
        'comprobante_tipo',
        'comprobante_serie',
        'comprobante_numero',
        'moneda',
        'subtotal',
        'impuestos',
        'total',
        'forma_pago',
        'estado',
        'usuario',
        'raw',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'venta_fecha' => 'datetime',
            'subtotal' => 'decimal:2',
            'impuestos' => 'decimal:2',
            'total' => 'decimal:2',
            'raw' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(VentaDetalle::class, 'venta_id', 'venta_id');
    }
}
