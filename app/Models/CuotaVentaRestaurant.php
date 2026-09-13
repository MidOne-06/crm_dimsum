<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CuotaVentaRestaurant extends Model
{
    protected $table = 'cuotas_ventas_restaurant';

    protected $fillable = ['codigo', 'local_id', 'local', 'periodo', 'cuota_sin_igv', 'cuota_con_igv'];

    protected function casts(): array
    {
        return [
            'periodo' => 'date',
            'cuota_sin_igv' => 'decimal:2',
            'cuota_con_igv' => 'decimal:2',
        ];
    }

    public function auditorias(): HasMany
    {
        return $this->hasMany(CuotaVentaRestaurantAuditoria::class, 'cuota_venta_restaurant_id');
    }
}
