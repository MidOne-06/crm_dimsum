<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
}
