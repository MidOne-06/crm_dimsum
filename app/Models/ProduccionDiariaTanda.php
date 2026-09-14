<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProduccionDiariaTanda extends Model
{
    protected $table = 'produccion_diaria_tandas';

    protected $fillable = [
        'cierre_id', 'producto_id', 'item_id', 'item_tipo', 'item_codigo', 'item_nombre',
        'unidad', 'cantidad', 'nota', 'registrado_por',
    ];

    protected function casts(): array
    {
        return ['cantidad' => 'decimal:4'];
    }

    public function cierre(): BelongsTo { return $this->belongsTo(ProduccionDiariaCierre::class, 'cierre_id'); }
    public function registrador(): BelongsTo { return $this->belongsTo(User::class, 'registrado_por'); }
}
