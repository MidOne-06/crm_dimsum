<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DirectivaTransferenciaSugerencia extends Model
{
    protected $table = 'directiva_transferencia_sugerencias';

    protected $fillable = [
        'fecha_despacho', 'dia_semana', 'local_id', 'local_nombre', 'item_id', 'item_tipo',
        'item_codigo', 'item_nombre', 'demanda_promedio', 'semanas_consideradas', 'saldo_actual',
        'cantidad_bruta', 'multiplo_aplicado', 'cantidad_sugerida', 'calculado_en',
    ];

    protected function casts(): array
    {
        return [
            'fecha_despacho' => 'date',
            'demanda_promedio' => 'decimal:4',
            'saldo_actual' => 'decimal:4',
            'cantidad_bruta' => 'decimal:4',
            'calculado_en' => 'datetime',
        ];
    }

    /** Menos de 3 semanas de histórico real detrás del promedio -- confiar poco en la sugerencia. */
    public function esConfianzaBaja(): bool
    {
        return $this->semanas_consideradas < 3;
    }
}
