<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CuotaVentaExterna extends Model
{
    protected $table = 'cuotas_ventas_externas';

    protected $fillable = ['canal_id', 'periodo', 'cuota_sin_igv', 'cuota_con_igv', 'actualizado_por'];

    protected function casts(): array
    {
        return ['periodo' => 'date', 'cuota_sin_igv' => 'decimal:2', 'cuota_con_igv' => 'decimal:2'];
    }

    public function canal(): BelongsTo
    {
        return $this->belongsTo(CanalVentaExterna::class, 'canal_id');
    }
}
