<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venta_componentes_combo', function (Blueprint $table): void {
            $table->id();
            $table->string('venta_id');
            $table->string('detalle_venta_item_id');
            $table->string('producto_compuesto_restaurant_id');
            $table->string('producto_restaurant_id');
            $table->string('descripcion')->nullable();
            $table->string('unidad')->nullable();
            $table->decimal('cantidad_por_combo', 14, 6)->default(0);
            $table->decimal('cantidad_total', 14, 6)->default(0);
            $table->foreignId('composicion_comercial_id')->nullable()
                ->constrained('productos_comerciales_composiciones')->nullOnDelete();
            $table->string('origen')->default('payload_restaurant');
            $table->timestampsTz();

            $table->foreign('venta_id')->references('venta_id')->on('ventas')->cascadeOnDelete();
            $table->unique(
                ['venta_id', 'detalle_venta_item_id', 'producto_restaurant_id'],
                'venta_componente_combo_unico',
            );
            $table->index('producto_compuesto_restaurant_id');
            $table->index('producto_restaurant_id');
        });

        Schema::table('venta_detalles', function (Blueprint $table): void {
            $table->boolean('es_producto_compuesto_restaurant')->default(false);
            $table->unsignedSmallInteger('componentes_payload_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('venta_detalles', function (Blueprint $table): void {
            $table->dropColumn(['es_producto_compuesto_restaurant', 'componentes_payload_count']);
        });

        Schema::dropIfExists('venta_componentes_combo');
    }
};
