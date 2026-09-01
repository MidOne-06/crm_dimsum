<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RequerimientoStockHistorico extends Model
{
    protected $table = 'requerimientos_stock_historicos';

    protected $fillable = [
        'erp_id', 'fecha_registro', 'fecha_abastecimiento', 'solicitado_por',
        'local_produccion', 'encargado', 'receptor', 'estado', 'observacion', 'sincronizado_en',
        'ultima_sincronizacion_error', 'payload_restaurant',
    ];

    protected function casts(): array
    {
        return [
            'fecha_registro' => 'datetime',
            'fecha_abastecimiento' => 'datetime',
            'sincronizado_en' => 'datetime',
            'payload_restaurant' => 'array',
        ];
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(RequerimientoStockHistoricoDetalle::class, 'requerimiento_stock_historico_id');
    }
}
