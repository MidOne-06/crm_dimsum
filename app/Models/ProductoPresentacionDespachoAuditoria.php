<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductoPresentacionDespachoAuditoria extends Model
{
    public $timestamps = false;

    protected $table = 'producto_presentacion_despacho_auditorias';

    protected $fillable = [
        'producto_presentacion_despacho_id', 'accion', 'motivo', 'usuario_id', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
