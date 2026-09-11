<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CanalVentaExterna extends Model
{
    protected $table = 'canales_ventas_externas';

    protected $fillable = ['codigo', 'nombre', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function cuotas(): HasMany
    {
        return $this->hasMany(CuotaVentaExterna::class, 'canal_id');
    }

    public function ventasDiarias(): HasMany
    {
        return $this->hasMany(VentaExternaDiaria::class, 'canal_id');
    }
}
