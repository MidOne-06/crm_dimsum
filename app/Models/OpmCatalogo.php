<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpmCatalogo extends Model
{
    protected $table = 'opm_catalogos';

    protected $fillable = [
        'sha256', 'origen_url', 'archivo_fuente', 'ruta_indice', 'hoja',
        'tipo_origen',
        'total_filas', 'total_nombres_unicos', 'total_combinaciones_unicas',
        'activo', 'obtenido_at', 'verificado_at',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'obtenido_at' => 'datetime',
            'verificado_at' => 'datetime',
        ];
    }

    public function ejecuciones(): HasMany
    {
        return $this->hasMany(OpmEjecucion::class, 'catalogo_id');
    }
}
