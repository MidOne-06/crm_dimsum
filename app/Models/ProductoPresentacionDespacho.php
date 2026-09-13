<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductoPresentacionDespacho extends Model
{
    protected $table = 'producto_presentaciones_despacho';

    protected $fillable = [
        'item_id', 'item_tipo', 'item_codigo', 'item_nombre', 'multiplo', 'nota',
        'activo', 'motivo_pausa', 'pausado_por', 'pausado_en',
    ];

    protected function casts(): array
    {
        return ['multiplo' => 'integer', 'activo' => 'boolean', 'pausado_en' => 'datetime'];
    }

    public function auditorias(): HasMany
    {
        return $this->hasMany(ProductoPresentacionDespachoAuditoria::class)->latest('created_at');
    }

    /** Redondea una cantidad hacia arriba al múltiplo válido más cercano. */
    public function redondear(float $cantidad): int
    {
        if ($this->multiplo <= 1) {
            return (int) ceil($cantidad);
        }

        return (int) (ceil($cantidad / $this->multiplo) * $this->multiplo);
    }
}
