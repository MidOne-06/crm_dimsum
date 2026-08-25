<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kardex_movimientos')) {
            return;
        }

        // Una fila por cada línea del Excel de Kardex General (report.php,
        // page=informemovimientoconsolidado_informestock). Se reemplaza por
        // rango (local_id + fecha_hora) en cada extracción, no se hace upsert
        // fila a fila -- el reporte no trae un id de movimiento único.
        Schema::create('kardex_movimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('extraccion_local_id')->nullable()->constrained('kardex_extraccion_locales')->nullOnDelete();
            $table->string('local_id')->index();
            $table->string('local_nombre')->nullable();
            $table->string('almacen')->nullable();
            $table->string('categoria')->nullable();
            $table->string('tipo_item')->nullable();
            $table->string('item_id')->nullable()->index();
            $table->string('item_nombre')->nullable();
            $table->date('fecha')->index();
            $table->string('hora')->nullable();
            $table->timestampTz('fecha_hora')->nullable()->index();
            $table->string('motivo')->nullable();
            $table->text('observacion')->nullable();
            $table->string('doc_entidad')->nullable();
            $table->string('entidad')->nullable();
            $table->string('unidad_medida')->nullable();
            $table->decimal('entrada', 14, 3)->default(0);
            $table->decimal('salida', 14, 3)->default(0);
            $table->decimal('stock', 14, 3)->default(0);
            $table->decimal('costo_unitario', 14, 4)->default(0);
            $table->decimal('costo_promedio', 14, 4)->default(0);
            $table->decimal('costo_movimiento', 14, 4)->default(0);
            $table->decimal('costo_operacion', 14, 4)->default(0);
            $table->decimal('stock_valorizado', 14, 4)->default(0);
            $table->string('canal_venta')->nullable();
            $table->string('id_producto_venta')->nullable();
            $table->string('cod_interno')->nullable();
            $table->string('producto')->nullable();
            $table->string('tienda')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kardex_movimientos');
    }
};
