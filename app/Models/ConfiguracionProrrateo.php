<?php

namespace App\Models;

use App\Models\Concerns\RegistraTrazabilidad;
use Illuminate\Database\Eloquent\Model;

/**
 * Estrategia activa de prorrateo cuando FABRICA no alcanza para cubrir todo
 * lo pedido. Fila única (patrón "settings") -- ver singleton().
 */
class ConfiguracionProrrateo extends Model
{
    use RegistraTrazabilidad;

    protected $fillable = ['estrategia'];

    public static function singleton(): self
    {
        return self::query()->firstOrCreate([], ['estrategia' => 'proporcional_venta']);
    }

    public static function etiquetas(): array
    {
        return [
            'proporcional_venta' => 'Proporcional a venta histórica',
            'orden_llegada' => 'Orden de llegada del requerimiento',
            'menor_stock' => 'Prioridad a menor stock (mayor riesgo de quiebre)',
            'manual' => 'Lista manual de prioridad por local',
        ];
    }
}
