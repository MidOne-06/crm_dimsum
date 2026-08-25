<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('venta_extracciones')) {
            return;
        }

        Schema::create('venta_extracciones', function (Blueprint $table) {
            $table->id();
            $table->string('estado')->default('pendiente')->index();
            $table->jsonb('filtros')->nullable();
            $table->integer('ventas_total_estimado')->nullable();
            $table->integer('ventas_procesadas')->default(0);
            $table->integer('ventas_guardadas')->default(0);
            $table->integer('items_guardados')->default(0);
            $table->integer('ventas_fallidas')->default(0);
            $table->text('mensaje_error')->nullable();
            $table->foreignId('iniciado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('iniciado_at')->nullable();
            $table->timestampTz('completado_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venta_extracciones');
    }
};
