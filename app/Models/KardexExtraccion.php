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
        $seg = (int) $this->iniciado_at->diffInSeconds($fin);

        return $seg >= 60
            ? floor($seg / 60).'m '.($seg % 60).'s'
            : $seg.'s';
    }

    public function getProgresoAttribute(): int
    {
        if (! $this->locales_total) {
            return 0;
        }

        $counts = $this->countsForDisplay();

        return (int) min(100, round((($counts['procesados'] + $counts['fallidos']) / max(1, $this->locales_total)) * 100));
    }

    /**
     * locales_procesados/locales_fallidos/movimientos_guardados en la
     * cabecera solo se recalculan al finalizar (finalizarSiListo) -- mientras
     * la extracción está "en_progreso" siguen en su valor inicial (0), así
     * que la barra de progreso y los contadores se quedarían en 0% todo el
     * proceso y saltarían de golpe al terminar. Mientras esté activa, se
     * cuenta en vivo desde el detalle por local; una vez completada, ya no
     * hace falta la consulta extra (la cabecera ya quedó con el total real).
     *
     * @return array{procesados: int, fallidos: int, movimientos: int}
     */
    public function countsForDisplay(): array
    {
        if ($this->estado !== 'en_progreso') {
            return [
                'procesados' => (int) $this->locales_procesados,
                'fallidos' => (int) $this->locales_fallidos,
                'movimientos' => (int) $this->movimientos_guardados,
            ];
        }

        $totales = $this->locales()
            ->selectRaw("count(*) filter (where estado = 'completado') as procesados")
            ->selectRaw("count(*) filter (where estado = 'fallido') as fallidos")
            ->selectRaw('coalesce(sum(movimientos_guardados), 0) as movimientos')
            ->first();

        return [
            'procesados' => (int) ($totales->procesados ?? 0),
            'fallidos' => (int) ($totales->fallidos ?? 0),
            'movimientos' => (int) ($totales->movimientos ?? 0),
        ];
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
                // El contador de la cabecera debe reflejar la suma de cada
                // local terminado. Usar incrementos dentro de jobs en paralelo
                // puede dejar un total histórico inflado si un worker se
                // reintenta o se reinicia después de guardar el local.
                $totales = KardexExtraccionLocal::query()
                    ->where('extraccion_id', $extraccionId)
                    ->selectRaw("count(*) filter (where estado = 'completado') as procesados")
                    ->selectRaw("count(*) filter (where estado = 'fallido') as fallidos")
                    ->selectRaw('coalesce(sum(movimientos_guardados), 0) as movimientos')
                    ->first();

                $extraccion->update([
                    'estado' => 'completado',
                    'locales_procesados' => (int) ($totales->procesados ?? 0),
                    'locales_fallidos' => (int) ($totales->fallidos ?? 0),
                    'movimientos_guardados' => (int) ($totales->movimientos ?? 0),
                    'completado_at' => now(),
                ]);
            }
        });
    }
}
