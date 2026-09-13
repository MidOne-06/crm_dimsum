<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de solo inserción de cada transición real de un ajuste de local
 * (creado/editado/retirado/aprobado/rechazado) -- ver docblock de la
 * migración. Nunca se actualiza ni se borra una fila de esta tabla.
 */
class DirectivaAjusteLocalHistorial extends Model
{
    public $timestamps = false;

    protected $table = 'directiva_ajustes_local_historial';

    protected $fillable = [
        'local_id', 'local_nombre', 'fecha_despacho', 'item_id', 'item_tipo', 'item_nombre',
        'accion', 'multiplos_solicitados', 'delta_unidades', 'motivo', 'comentario_admin',
        'usuario_id', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'fecha_despacho' => 'date',
            'multiplos_solicitados' => 'integer',
            'delta_unidades' => 'decimal:4',
            'created_at' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /** @param array<string, mixed> $extra */
    public static function registrar(DirectivaAjusteLocalDetalle $detalle, string $accion, array $extra = []): self
    {
        $solicitud = $detalle->solicitud ?? $detalle->solicitud()->first();

        return self::create(array_merge([
            'local_id' => $solicitud->local_id,
            'local_nombre' => $solicitud->local_nombre,
            'fecha_despacho' => $solicitud->fecha_despacho,
            'item_id' => $detalle->item_id,
            'item_tipo' => $detalle->item_tipo,
            'item_nombre' => $detalle->item_nombre,
            'accion' => $accion,
            'multiplos_solicitados' => $detalle->multiplos_solicitados,
            'delta_unidades' => $detalle->delta_unidades,
            'motivo' => $detalle->motivo,
            'comentario_admin' => $detalle->comentario_admin,
            'usuario_id' => auth()->id(),
            'created_at' => now(),
        ], $extra));
    }
}
