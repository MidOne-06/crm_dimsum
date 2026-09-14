<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProduccionProducto extends Model
{
    protected $table = 'produccion_productos';

    protected $fillable = ['codigo', 'nombre', 'unidad', 'activo'];

    protected function casts(): array { return ['activo' => 'boolean']; }

    public function tandas(): HasMany { return $this->hasMany(ProduccionDiariaTanda::class, 'producto_id'); }
    public function detalles(): HasMany { return $this->hasMany(ProduccionDiariaDetalle::class, 'producto_id'); }
}
