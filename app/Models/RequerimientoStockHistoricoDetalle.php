<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequerimientoStockHistoricoDetalle extends Model
{
    protected $table = 'requerimientos_stock_historicos_detalles';

    protected $fillable = [
        'erp_detalle_id', 'codigo', 'item', 'categoria', 'presentacion',
        'cantidad_solicitada', 'cantidad_despachada', 'cantidad_preparada', 'unidad', 'almacen', 'observacion',
        'activo', 'eliminado_en',
        'payload_restaurant',
    ];

    protected function casts(): array
    {
        return [
            'cantidad_solicitada' => 'decimal:4',
            'cantidad_despachada' => 'decimal:4',
            'cantidad_preparada' => 'decimal:4',
            'activo' => 'boolean',
            'eliminado_en' => 'datetime',
            'payload_restaurant' => 'array',
        ];
    }

    public function requerimiento(): BelongsTo
    {
        return $this->belongsTo(RequerimientoStockHistorico::class, 'requerimiento_stock_historico_id');
    }
}
