<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
class SalidaStock extends Model {
    use SoftDeletes;
    protected $table = 'salidas_stock';
    protected $fillable = ['sincronizacion_id','restaurant_id','local_id','local_nombre','fecha','hora','responsable','categoria','importe','razon','estado','payload_restaurant','sincronizado_en'];
    protected function casts(): array { return ['fecha'=>'date','importe'=>'decimal:4','payload_restaurant'=>'array','sincronizado_en'=>'datetime']; }
    public function detalles(): HasMany { return $this->hasMany(SalidaStockDetalle::class); }
    public function sincronizacion(): BelongsTo { return $this->belongsTo(SalidaStockSincronizacion::class, 'sincronizacion_id'); }
}
