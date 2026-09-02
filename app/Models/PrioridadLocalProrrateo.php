<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Orden de prioridad manual por local, usado solo cuando ConfiguracionProrrateo::estrategia = 'manual'. */
class PrioridadLocalProrrateo extends Model
{
    protected $fillable = ['local_id', 'local_nombre', 'orden'];
}
