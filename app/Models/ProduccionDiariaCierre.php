<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProduccionDiariaCierre extends Model
{
    protected $table = 'produccion_diaria_cierres';

    protected $fillable = ['fecha', 'area', 'estado', 'observacion', 'creado_por', 'enviado_por', 'enviado_en', 'aprobado_por', 'aprobado_en'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'enviado_en' => 'datetime', 'aprobado_en' => 'datetime'];
    }

    public function detalles(): HasMany { return $this->hasMany(ProduccionDiariaDetalle::class, 'cierre_id'); }
    public function creador(): BelongsTo { return $this->belongsTo(User::class, 'creado_por'); }
    public function aprobador(): BelongsTo { return $this->belongsTo(User::class, 'aprobado_por'); }
    public function auditorias(): HasMany { return $this->hasMany(ProduccionDiariaAuditoria::class, 'cierre_id'); }
    public function tandas(): HasMany { return $this->hasMany(ProduccionDiariaTanda::class, 'cierre_id'); }
    public function salidas(): HasMany { return $this->hasMany(ProduccionDiariaSalida::class, 'cierre_id'); }
}
