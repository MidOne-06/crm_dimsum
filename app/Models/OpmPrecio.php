<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OpmPrecio extends Model
{
    protected $table = 'opm_precios';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id', 'producto_id', 'cod_estab', 'cod_prod_e',
        'nombre_producto', 'concentracion',
        'precio1', 'precio2', 'precio3',
        'nombre_comercial', 'nom_grupo_ff', 'setcodigo',
        'direccion', 'telefono', 'departamento', 'provincia', 'distrito',
        'ubicodigo', 'fecha',
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
        $decoded = base64_decode(strtr($value, '-_', '+/') . $padding, true);

        // Conserva la compatibilidad con claves antiguas que no necesitaban codificación.
        if ($decoded !== false && str_contains($decoded, '|')) {
            $value = $decoded;
        }

        return parent::resolveRouteBindingQuery($query, $value, $field);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(OpmProducto::class, 'producto_id', 'id');
    }

    public function detalle(): HasOne
    {
        return $this->hasOne(OpmDetalle::class, 'detail_key', 'id');
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
