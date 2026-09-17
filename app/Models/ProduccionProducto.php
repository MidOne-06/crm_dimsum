<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProduccionProducto extends Model
{
    protected $table = 'produccion_productos';

    protected $fillable = ['produccion_categoria_id', 'restaurant_item_id', 'restaurant_item_tipo', 'restaurant_presentacion_id', 'codigo', 'nombre', 'unidad', 'activo'];

    protected function casts(): array { return ['activo' => 'boolean']; }

    public function categoria(): BelongsTo { return $this->belongsTo(ProduccionCategoria::class, 'produccion_categoria_id'); }
    public function tandas(): HasMany { return $this->hasMany(ProduccionDiariaTanda::class, 'producto_id'); }
    public function salidas(): HasMany { return $this->hasMany(ProduccionDiariaSalida::class, 'producto_id'); }
    public function detalles(): HasMany { return $this->hasMany(ProduccionDiariaDetalle::class, 'producto_id'); }
}
