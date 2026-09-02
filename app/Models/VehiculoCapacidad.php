<?php

namespace App\Models;

use App\Models\Concerns\RegistraTrazabilidad;
use Illuminate\Database\Eloquent\Model;

/** Tope físico (en tapers) de un tipo de vehículo/viaje -- puede obligar a recortar lo sugerido aunque el local individual tenga espacio. */
class VehiculoCapacidad extends Model
{
    use RegistraTrazabilidad;

    protected $fillable = ['nombre', 'capacidad_maxima_tapers'];
}
