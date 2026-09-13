<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ver docblock de la migración `2026_09_13_090000_...` -- registra cada
 * edición real de cantidad hecha durante un canje de guías internas
 * (individual o masivo), con quién y cuándo, sin bloquear el valor
 * editado: el usuario confirmó que recibir más de lo que trae la guía
 * original es un caso legítimo de su operación.
 */
class CanjeGuiaCantidadAuditoria extends Model
{
    protected $table = 'canje_guia_cantidad_auditorias';

    protected $fillable = [
        'guia_ids', 'movimiento_id', 'item_codigo', 'item_descripcion',
        'cantidad_original', 'cantidad_confirmada', 'origen', 'usuario_id',
    ];

    protected function casts(): array
    {
        return [
            'guia_ids' => 'array',
            'cantidad_original' => 'decimal:4',
            'cantidad_confirmada' => 'decimal:4',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /**
     * Persiste el detalle de `overrides` que devuelve el gateway
     * (`applyGuideQuantityOverrides`) para un grupo/guía ya confirmado --
     * usado por los 3 flujos que terminan llamando al mismo
     * `confirmGuideExchange` de API-TI (canje individual, canje masivo por
     * selección manual desde el listado, y el canje masivo filtrado de
     * "Canjear todo lo filtrado"). Sin `overrides` (caso normal: nadie
     * editó ninguna cantidad) no se escribe ninguna fila.
     *
     * @param  array<int, array{codigo: string, descripcion: string, cantidad_original: float, cantidad_confirmada: float}>  $overrides
     * @param  array<int, string>  $guiaIds
     */
    public static function registrarDesdeResultado(array $overrides, array $guiaIds, ?string $movimientoId, string $origen, ?int $usuarioId): void
    {
        foreach ($overrides as $override) {
            self::create([
                'guia_ids' => $guiaIds,
                'movimiento_id' => $movimientoId,
                'item_codigo' => (string) ($override['codigo'] ?? ''),
                'item_descripcion' => (string) ($override['descripcion'] ?? ''),
                'cantidad_original' => (float) ($override['cantidad_original'] ?? 0),
                'cantidad_confirmada' => (float) ($override['cantidad_confirmada'] ?? 0),
                'origen' => $origen,
                'usuario_id' => $usuarioId,
            ]);
        }
    }
}
