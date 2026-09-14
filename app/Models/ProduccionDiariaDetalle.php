<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProduccionDiariaDetalle extends Model
{
    protected $table = 'produccion_diaria_detalles';

    protected $fillable = ['cierre_id', 'item_id', 'item_tipo', 'item_codigo', 'item_nombre', 'unidad', 'stock_inicial', 'producido_hoy', 'stock_esperado', 'stock_final', 'diferencia', 'observacion'];

    protected function casts(): array
    {
        return ['stock_inicial' => 'decimal:4', 'producido_hoy' => 'decimal:4', 'stock_esperado' => 'decimal:4', 'stock_final' => 'decimal:4', 'diferencia' => 'decimal:4'];
    }

    public function cierre(): BelongsTo { return $this->belongsTo(ProduccionDiariaCierre::class, 'cierre_id'); }
}
