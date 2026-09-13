<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DirectivaAjusteLocalDetalle extends Model
{
    protected $table = 'directiva_ajustes_local_detalles';

    protected $fillable = [
        'solicitud_id', 'item_id', 'item_tipo', 'item_nombre', 'multiplo',
        'multiplos_solicitados', 'delta_unidades', 'motivo', 'estado',
        'comentario_admin', 'revisado_por', 'revisado_en',
    ];

    protected function casts(): array
    {
        return [
            'multiplo' => 'integer',
            'multiplos_solicitados' => 'integer',
            'delta_unidades' => 'decimal:4',
            'revisado_en' => 'datetime',
        ];
    }

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(DirectivaAjusteLocalSolicitud::class, 'solicitud_id');
    }

    public function revisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisado_por');
    }

    public function esEditable(): bool
    {
        return $this->estado === 'pendiente';
    }
}
