<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockInicialLocal extends Model
{
    protected $table = 'stock_iniciales_locales';

    protected $fillable = [
        'local_id', 'local_nombre', 'fecha_carga', 'cargado_por', 'estado', 'confirmado_en',
    ];

    protected function casts(): array
    {
        return [
            'fecha_carga' => 'date',
            'confirmado_en' => 'datetime',
        ];
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(StockInicialDetalle::class);
    }

    public function cargadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cargado_por');
    }

    public function estaConfirmado(): bool
    {
        return $this->estado === 'confirmado';
    }
}
