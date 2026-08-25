<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpmProducto extends Model
{
    protected $table = 'opm_productos';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id', 'nombre_producto', 'principio_activo', 'concentracion', 'forma',
        'grupo', 'cod_grupo_ff', 'cant_precios',
        'min_precio1', 'max_precio1', 'min_precio2',
        'parametro_id', 'ejecucion_id',
    ];

    /**
     * Los identificadores de origen pueden contener caracteres reservados de URL
     * (por ejemplo, una barra dentro de la concentración). Se codifican para
     * que el enlace de detalle siempre represente un solo segmento de ruta.
     */
    public function getRouteKey(): string
    {
        return rtrim(strtr(base64_encode((string) $this->getKey()), '+/', '-_'), '=');
    }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $padding = str_repeat('=', (4 - strlen($value) % 4) % 4);
        $decoded = base64_decode(strtr($value, '-_', '+/').$padding, true);

        // Conserva la compatibilidad con claves antiguas que no necesitaban codificación.
        if ($decoded !== false && str_contains($decoded, '|')) {
            $value = $decoded;
        }

        return parent::resolveRouteBindingQuery($query, $value, $field);
    }

    public function precios(): HasMany
    {
        return $this->hasMany(OpmPrecio::class, 'producto_id', 'id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(OpmProductoAlias::class, 'producto_id');
    }

    public function candidatos(): HasMany
    {
        return $this->hasMany(OpmProductoCandidato::class, 'producto_id');
    }

    public function parametro(): BelongsTo
    {
        return $this->belongsTo(OpmParametro::class, 'parametro_id');
    }

    public function ejecucion(): BelongsTo
    {
        return $this->belongsTo(OpmEjecucion::class, 'ejecucion_id');
    }
}
