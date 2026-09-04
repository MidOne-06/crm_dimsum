<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfiguracionSincronizacion extends Model
{
    protected $table = 'configuracion_sincronizaciones';

    protected $fillable = [
        'modulo', 'nombre', 'activo', 'cron_expresion', 'desactivado_por', 'desactivado_en', 'nota',
    ];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'desactivado_en' => 'datetime'];
    }

    /**
     * Si el módulo no tiene fila todavía (nunca se corrió la migración
     * semilla, o se agregó un módulo nuevo sin registrar), se asume activo
     * -- el comportamiento de siempre, antes de que existiera este panel.
     * Nunca debe bloquear silenciosamente un sync que nadie apagó a propósito.
     */
    public static function activo(string $modulo): bool
    {
        return (bool) (static::query()->where('modulo', $modulo)->value('activo') ?? true);
    }

    public function desactivar(?string $por, ?string $nota = null): void
    {
        $this->update(['activo' => false, 'desactivado_por' => $por, 'desactivado_en' => now(), 'nota' => $nota]);
    }

    public function activar(): void
    {
        $this->update(['activo' => true, 'desactivado_por' => null, 'desactivado_en' => null]);
    }
}
