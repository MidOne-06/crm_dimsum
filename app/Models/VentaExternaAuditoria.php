<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VentaExternaAuditoria extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'venta_externa_auditorias';

    protected $fillable = ['venta_externa_diaria_id', 'accion', 'antes', 'despues', 'usuario_id'];

    protected function casts(): array
    {
        return ['antes' => 'array', 'despues' => 'array', 'created_at' => 'datetime'];
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(VentaExternaDiaria::class, 'venta_externa_diaria_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
