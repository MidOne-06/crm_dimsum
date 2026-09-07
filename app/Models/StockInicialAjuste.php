<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockInicialAjuste extends Model
{
    protected $table = 'stock_iniciales_ajustes';

    protected $fillable = [
        'local_id', 'local_nombre', 'item_id', 'item_tipo', 'item_nombre',
        'cantidad_ajuste', 'motivo', 'ajustado_por', 'ajustado_en',
    ];

    protected function casts(): array
    {
        return [
            'cantidad_ajuste' => 'decimal:4',
            'ajustado_en' => 'datetime',
        ];
    }

    public function ajustadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ajustado_por');
    }
}
