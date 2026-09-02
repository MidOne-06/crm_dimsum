<?php

namespace App\Models;

use App\Models\Concerns\RegistraTrazabilidad;
use Illuminate\Database\Eloquent\Model;

/** Orden de prioridad manual por local, usado solo cuando ConfiguracionProrrateo::estrategia = 'manual'. */
class PrioridadLocalProrrateo extends Model
{
    use RegistraTrazabilidad;

    protected $fillable = ['local_id', 'local_nombre', 'orden'];
}
