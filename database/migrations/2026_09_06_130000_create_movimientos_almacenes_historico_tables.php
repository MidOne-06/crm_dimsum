<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_almacenes_sincronizaciones', function (Blueprint $table): void {
            $table->id();
            $table->date('fecha_inicio')->index();
            $table->date('fecha_fin')->index();
            $table->string('estado')->default('pendiente')->index();
            $table->jsonb('filtros')->nullable();
            $table->foreignId('iniciado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('paginas_total')->default(0);
            $table->unsignedInteger('paginas_procesadas')->default(0);
            $table->unsignedInteger('cabeceras_guardadas')->default(0);
            $table->unsignedInteger('detalles_guardados')->default(0);
            $table->unsignedInteger('cabeceras_eliminadas')->default(0);
            $table->unsignedInteger('errores')->default(0);
            $table->text('mensaje_error')->nullable();
            $table->timestampTz('iniciado_en')->nullable();
            $table->timestampTz('completado_en')->nullable();
            $table->timestampsTz();
        });

        Schema::create('movimientos_almacenes_historico', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sincronizacion_id')->nullable()->constrained('movimientos_almacenes_sincronizaciones')->nullOnDelete();
            $table->string('restaurant_id')->unique();
            $table->timestampTz('fecha')->nullable()->index();
            $table->string('local_origen_id')->nullable()->index();
            $table->string('local_origen')->nullable()->index();
            $table->string('almacen_origen_id')->nullable()->index();
            $table->string('almacen_origen')->nullable();
            $table->string('local_destino_id')->nullable()->index();
            $table->string('local_destino')->nullable()->index();
            $table->string('almacen_destino_id')->nullable()->index();
            $table->string('almacen_destino')->nullable();
            $table->string('encargado')->nullable();
            $table->string('receptor')->nullable();
            $table->string('registrado_por')->nullable();
            $table->unsignedInteger('total_items')->default(0);
            $table->decimal('valorizado', 18, 4)->default(0);
            $table->string('estado_codigo')->nullable()->index();
            $table->string('estado')->nullable();
            $table->string('estado_recepcion_codigo')->nullable()->index();
            $table->string('estado_recepcion')->nullable();
            $table->text('observacion')->nullable();
            $table->jsonb('payload_restaurant')->nullable();
            $table->timestampTz('sincronizado_en')->nullable()->index();
            $table->softDeletesTz();
            $table->timestampsTz();
        });

        Schema::create('movimientos_almacenes_detalles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('movimiento_id')->constrained('movimientos_almacenes_historico')->cascadeOnDelete();
            $table->string('restaurant_id');
            $table->string('item_id')->nullable()->index();
            $table->string('codigo')->nullable()->index();
            $table->string('item')->nullable();
            $table->string('presentacion')->nullable();
            $table->string('unidad')->nullable();
            $table->decimal('cantidad', 18, 4)->default(0);
            $table->string('almacen_origen')->nullable();
            $table->string('almacen_destino')->nullable();
            $table->decimal('valorizado', 18, 4)->default(0);
            $table->string('estado')->nullable();
            $table->jsonb('payload_restaurant')->nullable();
            $table->softDeletesTz();
            $table->timestampsTz();
            $table->unique(['movimiento_id', 'restaurant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_almacenes_detalles');
        Schema::dropIfExists('movimientos_almacenes_historico');
        Schema::dropIfExists('movimientos_almacenes_sincronizaciones');
    }
};
