<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Rango de fechas en que un transportista no entrega. Mientras hay una
 * ausencia vigente, sus locales de titular pasan al suplente en
 * RegistrarEntrega (y la entrega del suplente queda auto-marcada como
 * reemplazo, con este motivo).
 */
class TransportistaAusencia extends Model
{
    protected $table = 'transportista_ausencias';

    protected $fillable = ['user_id', 'fecha_inicio', 'fecha_fin', 'motivo'];

    protected function casts(): array
    {
        return ['fecha_inicio' => 'date', 'fecha_fin' => 'date'];
    }

    public function transportista(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function vigenteEn(Carbon $fecha): bool
    {
        return $fecha->betweenIncluded($this->fecha_inicio, $this->fecha_fin);
    }

    public function estado(): string
    {
        $hoy = today();
        if ($this->fecha_fin->lt($hoy)) {
            return 'pasada';
        }

        return $this->fecha_inicio->gt($hoy) ? 'proxima' : 'vigente';
    }
}
