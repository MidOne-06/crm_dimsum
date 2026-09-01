<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
class SalidaStockDetalle extends Model {
    use SoftDeletes;
    protected $fillable = ['salida_stock_id','restaurant_id','item_id','item_codigo','item','tipo','almacen_id','almacen','unidad','cantidad','costo','total','payload_restaurant'];
    protected function casts(): array { return ['cantidad'=>'decimal:4','costo'=>'decimal:4','total'=>'decimal:4','payload_restaurant'=>'array']; }
    public function salida(): BelongsTo { return $this->belongsTo(SalidaStock::class); }
}
