<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CanjeMasivo extends Model
{
    protected $table = 'canjes_masivos';

    protected $fillable = [
        'estado', 'filtros', 'total_guias_filtro', 'total_guias_procesables', 'total_guias_excluidas',
        'total_grupos_estimados', 'total_valorizado_estimado', 'total_guias_confirmadas', 'total_guias_fallidas',
        'total_movimientos_creados', 'resultado', 'mensaje_error', 'iniciado_por',
        'previsualizado_en', 'confirmado_en', 'completado_en',
    ];

    protected function casts(): array
    {
        return [
            'filtros' => 'array',
            'resultado' => 'array',
            'total_valorizado_estimado' => 'decimal:4',
            'previsualizado_en' => 'datetime',
            'confirmado_en' => 'datetime',
            'completado_en' => 'datetime',
        ];
    }

    public function iniciadoPor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'iniciado_por');
    }

    public function estaListoParaConfirmar(): bool
    {
        return $this->estado === 'listo' && $this->total_guias_procesables > 0;
    }

    public function estaEnCurso(): bool
    {
        return in_array($this->estado, ['previsualizando', 'confirmando'], true);
    }
}
