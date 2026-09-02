<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Registra en configuracion_dt_eventos cada creación/edición/borrado del
 * modelo que use este trait -- quién lo hizo y con qué valores antes/después.
 * Aplicado a los 11 modelos de configuración de la Directiva de Transferencia
 * (tapers + los 9 módulos de casuística operativa) para que "todo lo
 * implementado tenga trazabilidad", no solo created_at/updated_at.
 */
trait RegistraTrazabilidad
{
    protected static function bootRegistraTrazabilidad(): void
    {
        static::created(function ($model): void {
            static::registrarEventoTrazabilidad($model, 'creado', null, $model->getAttributes());
        });

        static::updated(function ($model): void {
            $cambios = $model->getChanges();
            unset($cambios['updated_at']);
            if ($cambios === []) {
                return;
            }
            static::registrarEventoTrazabilidad($model, 'actualizado', array_intersect_key($model->getOriginal(), $cambios), $cambios);
        });

        static::deleted(function ($model): void {
            static::registrarEventoTrazabilidad($model, 'eliminado', $model->getOriginal(), null);
        });
    }

    private static function registrarEventoTrazabilidad($model, string $accion, ?array $antes, ?array $despues): void
    {
        DB::table('configuracion_dt_eventos')->insert([
            'tabla' => $model->getTable(),
            'registro_id' => $model->getKey(),
            'accion' => $accion,
            'datos_antes' => $antes !== null ? json_encode($antes, JSON_UNESCAPED_UNICODE) : null,
            'datos_despues' => $despues !== null ? json_encode($despues, JSON_UNESCAPED_UNICODE) : null,
            'usuario_id' => auth()->id(),
            'usuario_nombre' => auth()->user()?->name,
            'created_at' => now(),
        ]);
    }
}
