<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OpmParametro extends Model
{
    protected $table = 'opm_parametros';

    protected $fillable = [
        'nombre',
        'cod_categoria', 'desc_categoria',
        'cod_tipo',      'desc_tipo',
        'cod_departamento', 'desc_departamento',
        'cod_provincia',    'desc_provincia',
        'cod_distrito',     'desc_distrito',
        'estado',
        'total_productos', 'total_precios', 'total_detalles',
        'ejecutado_at',
    ];

    protected $casts = [
        'ejecutado_at' => 'datetime',
    ];

    public function ejecuciones(): HasMany
    {
        return $this->hasMany(OpmEjecucion::class, 'parametro_id')->latest('iniciado_at');
    }

    public function ultimaEjecucion(): HasOne
    {
        return $this->hasOne(OpmEjecucion::class, 'parametro_id')->latestOfMany('iniciado_at');
    }

    public function productos(): HasMany
    {
        return $this->hasMany(OpmProducto::class, 'parametro_id');
    }

    public function precios(): HasMany
    {
        return $this->hasMany(OpmPrecio::class, 'parametro_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(OpmDetalle::class, 'parametro_id');
    }

    public function getProgressAttribute(): ?array
    {
        $ej = $this->ejecuciones()->first();
        if (!$ej) return null;
        $file = storage_path("app/opm_batch/parametro_{$this->id}/ejecucion_{$ej->id}/progress.json");
        if (!file_exists($file)) return null;
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    public function getSufijoBatchAttribute(): string
    {
        return implode('|', [
            $this->cod_categoria,
            $this->cod_tipo,
            $this->cod_departamento,
            $this->cod_provincia,
            $this->cod_distrito,
        ]);
    }
}
