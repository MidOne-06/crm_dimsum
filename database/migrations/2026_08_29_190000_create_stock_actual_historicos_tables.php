<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_cuadre_soportes', function (Blueprint $table) {
            $table->id();
            $table->string('estado')->default('pendiente')->index();
            $table->jsonb('filtros')->nullable();
            $table->unsignedInteger('paginas_total')->default(0);
            $table->unsignedInteger('paginas_procesadas')->default(0);
            $table->unsignedInteger('cuadres_guardados')->default(0);
            $table->unsignedInteger('detalles_guardados')->default(0);
            $table->text('mensaje_error')->nullable();
            $table->timestampTz('iniciado_at')->nullable();
            $table->timestampTz('completado_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('stock_cuadres', function (Blueprint $table) {
            $table->id();
            $table->string('restaurant_id')->unique();
            $table->foreignId('soporte_id')->nullable()->constrained('stock_cuadre_soportes')->nullOnDelete();
            $table->string('local_id')->nullable()->index();
            $table->string('local_nombre')->nullable()->index();
            $table->timestampTz('fecha_cuadre')->nullable()->index();
            $table->timestampTz('fecha_registro')->nullable()->index();
            $table->string('estado')->nullable()->index();
            $table->string('tipo')->nullable()->index();
            $table->string('motivo')->nullable();
            $table->string('responsable')->nullable();
            $table->decimal('sobrevalorizacion', 18, 4)->default(0);
            $table->decimal('perdida', 18, 4)->default(0);
            $table->string('checksum', 64)->nullable()->index();
            $table->jsonb('payload_restaurant')->nullable();
            $table->timestampTz('sincronizado_en')->nullable()->index();
            $table->timestampsTz();
        });

        Schema::create('stock_cuadre_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_cuadre_id')->constrained('stock_cuadres')->cascadeOnDelete();
            $table->string('restaurant_id');
            $table->string('item_id')->nullable()->index();
            $table->string('item_codigo')->nullable()->index();
            $table->string('item')->nullable();
            $table->string('tipo')->nullable();
            $table->string('almacen_id')->nullable()->index();
            $table->string('almacen')->nullable()->index();
            $table->string('unidad')->nullable();
            $table->decimal('aumento', 18, 4)->default(0);
            $table->decimal('disminucion', 18, 4)->default(0);
            $table->decimal('costo', 18, 4)->default(0);
            $table->decimal('impuestos', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);
            $table->decimal('stock_anterior', 18, 4)->default(0);
            $table->decimal('stock_actual', 18, 4)->default(0);
            $table->decimal('valorizacion', 18, 4)->default(0);
            $table->boolean('activo')->default(true)->index();
            $table->jsonb('payload_restaurant')->nullable();
            $table->timestampsTz();
            $table->unique(['stock_cuadre_id', 'restaurant_id'], 'stock_cuadre_detalle_restaurant_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_cuadre_detalles');
        Schema::dropIfExists('stock_cuadres');
        Schema::dropIfExists('stock_cuadre_soportes');
    }
};
