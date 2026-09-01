<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockCuadre extends Model
{
    protected $table = 'stock_cuadres';

    protected $fillable = ['restaurant_id', 'soporte_id', 'local_id', 'local_nombre', 'fecha_cuadre', 'fecha_registro', 'estado', 'tipo', 'motivo', 'responsable', 'sobrevalorizacion', 'perdida', 'checksum', 'payload_restaurant', 'sincronizado_en'];

    protected function casts(): array
    {
        return ['fecha_cuadre' => 'datetime', 'fecha_registro' => 'datetime', 'sobrevalorizacion' => 'decimal:4', 'perdida' => 'decimal:4', 'payload_restaurant' => 'array', 'sincronizado_en' => 'datetime'];
    }

    public function soporte(): BelongsTo { return $this->belongsTo(StockCuadreSoporte::class, 'soporte_id'); }
    public function detalles(): HasMany { return $this->hasMany(StockCuadreDetalle::class, 'stock_cuadre_id'); }
}
