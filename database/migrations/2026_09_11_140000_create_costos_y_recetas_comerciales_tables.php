<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos_comerciales_costos', function (Blueprint $table): void {
            $table->id();
            $table->string('producto_restaurant_id')->index();
            $table->decimal('costo_unitario', 14, 6);
            $table->date('vigente_desde')->index();
            $table->text('observacion')->nullable();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['producto_restaurant_id', 'vigente_desde'], 'costo_comercial_vigencia_unica');
        });

        Schema::create('productos_comerciales_recetas_manuales', function (Blueprint $table): void {
            $table->id();
            $table->string('producto_restaurant_id')->index();
            $table->date('vigente_desde')->index();
            $table->text('observacion')->nullable();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['producto_restaurant_id', 'vigente_desde'], 'receta_manual_vigencia_unica');
        });

        Schema::create('productos_comerciales_recetas_manuales_componentes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('receta_manual_id')
                ->constrained('productos_comerciales_recetas_manuales')
                ->cascadeOnDelete();
            $table->string('componente_restaurant_producto_id');
            $table->decimal('cantidad_por_producto', 14, 6);
            $table->timestampsTz();

            $table->unique(['receta_manual_id', 'componente_restaurant_producto_id'], 'receta_manual_componente_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productos_comerciales_recetas_manuales_componentes');
        Schema::dropIfExists('productos_comerciales_recetas_manuales');
        Schema::dropIfExists('productos_comerciales_costos');
    }
};
