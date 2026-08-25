<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class KardexExtraccion extends Model
{
    protected $table = 'kardex_extracciones';

    protected $fillable = [
        'estado',
        'filtros',
        'locales_total',
        'locales_procesados',
        'locales_fallidos',
        'movimientos_guardados',
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

    public function locales(): HasMany
    {
        return $this->hasMany(KardexExtraccionLocal::class, 'extraccion_id');
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
        if (! $this->locales_total) {
            return 0;
        }

        return (int) min(100, round((($this->locales_procesados + $this->locales_fallidos) / max(1, $this->locales_total)) * 100));
    }

    public static function finalizarSiListo(int $extraccionId): void
    {
        DB::transaction(function () use ($extraccionId): void {
            $extraccion = static::query()->lockForUpdate()->find($extraccionId);

            if (! $extraccion || $extraccion->estado !== 'en_progreso') {
                return;
            }

            $pendientes = KardexExtraccionLocal::query()
                ->where('extraccion_id', $extraccionId)
                ->whereIn('estado', ['pendiente', 'en_progreso'])
                ->exists();

            if (! $pendientes) {
                $extraccion->update(['estado' => 'completado', 'completado_at' => now()]);
            }
        });
    }
}
