<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpmEjecucion extends Model
{
    protected $table = 'opm_ejecuciones';

    protected $fillable = [
        'parametro_id', 'catalogo_id', 'catalogo_hash', 'consulta_producto', 'modo_extraccion', 'estado',
        'total_productos', 'total_precios', 'total_detalles',
        'iniciado_at', 'completado_at',
    ];

    protected $casts = [
        'iniciado_at' => 'datetime',
        'completado_at' => 'datetime',
    ];

    public function parametro(): BelongsTo
    {
        return $this->belongsTo(OpmParametro::class, 'parametro_id');
    }

    public function catalogo(): BelongsTo
    {
        return $this->belongsTo(OpmCatalogo::class, 'catalogo_id');
    }

    public function productos(): HasMany
    {
        return $this->hasMany(OpmProducto::class, 'ejecucion_id');
    }

    public function precios(): HasMany
    {
        return $this->hasMany(OpmPrecio::class, 'ejecucion_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(OpmDetalle::class, 'ejecucion_id');
    }

    public function getDuracionAttribute(): ?string
    {
        if (! $this->iniciado_at) {
            return null;
        }
        $fin = $this->completado_at ?? now();
        $seg = $this->iniciado_at->diffInSeconds($fin);

        return $seg >= 60
            ? floor($seg / 60).'m '.($seg % 60).'s'
            : $seg.'s';
    }
}
