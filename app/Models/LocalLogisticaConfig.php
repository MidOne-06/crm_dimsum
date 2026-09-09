<?php

namespace App\Models;

use App\Models\Concerns\RegistraTrazabilidad;
use Illuminate\Database\Eloquent\Model;

/**
 * Configuración operativa por local para la Directiva de Transferencia:
 * hora de llegada por defecto, ventana de recepción, inactividad temporal y
 * modo de arranque para un local nuevo. No hay "ruta" como entidad.
 *
 * OJO: `frecuencia_dias` queda como dato de referencia/histórico -- desde
 * el 2026-09-09 `DirectivaTransferenciaService` ya NO lo usa para calcular
 * la fecha de despacho. Lo reemplazó `LocalDiaSinDt` (días de la semana en
 * que un local no genera DT), que permite cualquier patrón real (no solo
 * "cada N días" parejo) -- ver el docblock de esa clase y de
 * `DirectivaTransferenciaService::proximaLlegada()`.
 */
class LocalLogisticaConfig extends Model
{
    use RegistraTrazabilidad;

    protected $fillable = [
        'local_id', 'local_nombre', 'frecuencia_dias', 'hora_llegada_estimada',
        'ventana_recepcion_inicio', 'ventana_recepcion_fin',
        'inactivo_desde', 'inactivo_hasta', 'inactivo_motivo',
        'modo_arranque', 'local_gemelo_id',
    ];

    protected function casts(): array
    {
        return [
            'inactivo_desde' => 'date',
            'inactivo_hasta' => 'date',
        ];
    }

    /** ¿Este local está marcado inactivo hoy (o en la fecha dada)? */
    public function inactivoEn(\DateTimeInterface|string|null $fecha = null): bool
    {
        if (! $this->inactivo_desde || ! $this->inactivo_hasta) {
            return false;
        }
        $fecha = $fecha ? \Carbon\Carbon::parse($fecha) : now();

        return $fecha->between($this->inactivo_desde, $this->inactivo_hasta);
    }
}
