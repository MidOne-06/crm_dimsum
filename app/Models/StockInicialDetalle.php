<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockInicialDetalle extends Model
{
    protected $table = 'stock_iniciales_detalles';

    protected $fillable = [
        'stock_inicial_local_id', 'item_id', 'item_tipo', 'item_codigo', 'item_nombre',
        'unidad', 'cantidad_inicial',
    ];

    protected function casts(): array
    {
        return ['cantidad_inicial' => 'decimal:4'];
    }

    public function cabecera(): BelongsTo
    {
        return $this->belongsTo(StockInicialLocal::class, 'stock_inicial_local_id');
    }
}
