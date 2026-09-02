<?php

namespace App\Models;

use App\Models\Concerns\RegistraTrazabilidad;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Techo de producción diaria declarado por producto en FABRICA. El
 * histórico de lo REALMENTE producido no se duplica en una tabla nueva --
 * se lee directo de kardex_movimientos (FABRICA ya existe ahí como local),
 * sumando la columna `entrada` por producto y fecha.
 */
class FabricaCapacidadProducto extends Model
{
    use RegistraTrazabilidad;

    protected $fillable = ['item_id', 'item_codigo', 'item_nombre', 'capacidad_maxima_dia'];

    /** Producido real (suma de entradas a FABRICA) para este producto en una fecha dada. */
    public function producidoEn(\DateTimeInterface|string $fecha): float
    {
        return (float) DB::table('kardex_movimientos')
            ->where('local_nombre', 'FABRICA')
            ->where('item_id', $this->item_id)
            ->whereDate('fecha', $fecha)
            ->sum('entrada');
    }
}
