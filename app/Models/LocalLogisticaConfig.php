<?php

namespace App\Models;

use App\Models\Concerns\RegistraTrazabilidad;
use Illuminate\Database\Eloquent\Model;

/**
 * Configuración operativa por local para la Directiva de Transferencia:
 * cadencia de reparto, hora de llegada, ventana de recepción, inactividad
 * temporal y modo de arranque para un local nuevo. No hay "ruta" como
 * entidad -- la agrupación de despacho de cada día se calcula a partir de
 * frecuencia_dias, no de un catálogo de rutas pre-asignadas.
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
