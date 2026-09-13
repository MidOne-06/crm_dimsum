<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CuotaVentaRestaurantAuditoria extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'cuota_venta_restaurant_auditorias';

    protected $fillable = ['cuota_venta_restaurant_id', 'accion', 'antes', 'despues', 'usuario_id'];

    protected function casts(): array
    {
        return ['antes' => 'array', 'despues' => 'array', 'created_at' => 'datetime'];
    }

    public function cuota(): BelongsTo
    {
        return $this->belongsTo(CuotaVentaRestaurant::class, 'cuota_venta_restaurant_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
