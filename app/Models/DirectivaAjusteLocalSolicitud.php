<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DirectivaAjusteLocalSolicitud extends Model
{
    protected $table = 'directiva_ajustes_local_solicitudes';

    protected $fillable = ['local_id', 'local_nombre', 'fecha_despacho', 'creado_por'];

    protected function casts(): array
    {
        return ['fecha_despacho' => 'date'];
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(DirectivaAjusteLocalDetalle::class, 'solicitud_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }
}
