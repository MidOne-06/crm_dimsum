<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProduccionDiariaAuditoria extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'produccion_diaria_auditorias';

    protected $fillable = ['cierre_id', 'accion', 'antes', 'despues', 'usuario_id'];

    protected function casts(): array { return ['antes' => 'array', 'despues' => 'array', 'created_at' => 'datetime']; }

    public function cierre(): BelongsTo { return $this->belongsTo(ProduccionDiariaCierre::class, 'cierre_id'); }
    public function usuario(): BelongsTo { return $this->belongsTo(User::class, 'usuario_id'); }
}
