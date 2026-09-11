<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos_comerciales_restaurant', function (Blueprint $table): void {
            $table->id();
            $table->string('restaurant_producto_id')->unique();
            $table->string('nombre')->nullable();
            $table->string('descripcion_venta')->nullable();
            $table->string('codigo')->nullable()->index();
            $table->string('unidad')->nullable();
            $table->boolean('es_combo')->default(false)->index();
            $table->boolean('lleva_ingredientes')->default(false);
            $table->boolean('tiene_composicion_restaurant')->default(false)->index();
            $table->jsonb('fuente_restaurant')->nullable();
            $table->timestampTz('sincronizado_en')->nullable();
            $table->timestampsTz();
        });

        Schema::create('productos_comerciales_composiciones', function (Blueprint $table): void {
            $table->id();
            $table->string('producto_restaurant_id')->index();
            $table->string('huella', 64);
            $table->timestampTz('primera_venta_en')->nullable();
            $table->timestampTz('ultima_venta_en')->nullable();
            $table->jsonb('fuente_restaurant')->nullable();
            $table->timestampsTz();

            $table->unique(['producto_restaurant_id', 'huella'], 'composicion_restaurant_unica');
        });

        Schema::create('productos_comerciales_componentes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('composicion_id')->constrained('productos_comerciales_composiciones')->cascadeOnDelete();
            $table->string('componente_restaurant_producto_id');
            $table->string('nombre')->nullable();
            $table->string('descripcion_venta')->nullable();
            $table->string('codigo')->nullable();
            $table->string('unidad')->nullable();
            $table->decimal('cantidad_por_producto', 14, 6)->default(0);
            $table->jsonb('fuente_restaurant')->nullable();
            $table->timestampsTz();

            $table->unique(['composicion_id', 'componente_restaurant_producto_id'], 'componente_restaurant_unico');
        });

        Schema::table('venta_detalles', function (Blueprint $table): void {
            $table->string('producto_restaurant_id')->nullable()->index()->after('venta_id');
            $table->foreignId('composicion_comercial_id')->nullable()->after('producto_restaurant_id')
                ->constrained('productos_comerciales_composiciones')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('venta_detalles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('composicion_comercial_id');
            $table->dropColumn('producto_restaurant_id');
        });

        Schema::dropIfExists('productos_comerciales_componentes');
        Schema::dropIfExists('productos_comerciales_composiciones');
        Schema::dropIfExists('productos_comerciales_restaurant');
    }
};
