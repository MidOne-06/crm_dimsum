<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Forzado manual del estado activo/inactivo de un local para la Directiva
 * de Transferencia -- ver docblock de la migración
 * `2026_09_11_120000_create_local_activo_overrides` para el porqué. Sin
 * fila para un local = se usa el cálculo automático
 * (`DirectivaTransferenciaService::localesConVentaActiva()`).
 */
class LocalActivoOverride extends Model
{
    protected $table = 'local_activo_overrides';

    protected $fillable = ['local_id', 'local_nombre', 'activo', 'motivo', 'actualizado_por'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por');
    }
}
