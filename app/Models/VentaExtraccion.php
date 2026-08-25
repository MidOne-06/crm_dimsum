<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class VentaExtraccion extends Model
{
    protected $table = 'venta_extracciones';

    protected $fillable = [
        'estado',
        'filtros',
        'ventas_total_estimado',
        'ventas_procesadas',
        'ventas_guardadas',
        'items_guardados',
        'ventas_fallidas',
        'paginas_total',
        'paginas_procesadas',
        'mensaje_error',
        'iniciado_por',
        'iniciado_at',
        'completado_at',
    ];

    protected function casts(): array
    {
        return [
            'filtros' => 'array',
            'iniciado_at' => 'datetime',
            'completado_at' => 'datetime',
        ];
    }

    public function iniciadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'iniciado_por');
    }

    public function getDuracionAttribute(): ?string
    {
        if (! $this->iniciado_at) {
            return null;
        }

        $fin = $this->completado_at ?? now();
        $seg = $this->iniciado_at->diffInSeconds($fin);

        return $seg >= 60
            ? floor($seg / 60).'m '.($seg % 60).'s'
            : $seg.'s';
    }

    public function getProgresoAttribute(): int
    {
        if (! $this->ventas_total_estimado) {
            return 0;
        }

        return (int) min(100, round(($this->ventas_procesadas / max(1, $this->ventas_total_estimado)) * 100));
    }

    public static function finalizarSiListo(int $extraccionId): void
    {
        DB::transaction(function () use ($extraccionId): void {
            $extraccion = static::query()->lockForUpdate()->find($extraccionId);

            if (! $extraccion || $extraccion->estado !== 'en_progreso') {
                return;
            }

            $pagesReady = (int) $extraccion->paginas_procesadas >= (int) $extraccion->paginas_total;
            $workPending = DB::table('venta_extraccion_ventas')
                ->where('extraccion_id', $extraccionId)
                ->whereIn('estado', ['pendiente', 'en_progreso'])
                ->exists();

            if ($pagesReady && ! $workPending) {
                $extraccion->update(['estado' => 'completado', 'completado_at' => now()]);
            }
        });
    }
}
