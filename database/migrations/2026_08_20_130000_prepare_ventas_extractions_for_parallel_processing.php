<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venta_extracciones', function (Blueprint $table) {
            $table->unsignedInteger('paginas_total')->default(0);
            $table->unsignedInteger('paginas_procesadas')->default(0);
        });

        Schema::create('venta_extraccion_paginas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('extraccion_id')->constrained('venta_extracciones')->cascadeOnDelete();
            $table->unsignedInteger('pagina');
            $table->string('estado')->default('pendiente')->index();
            $table->timestampsTz();
            $table->unique(['extraccion_id', 'pagina']);
        });

        Schema::create('venta_extraccion_ventas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('extraccion_id')->constrained('venta_extracciones')->cascadeOnDelete();
            $table->string('venta_id');
            $table->string('estado')->default('pendiente')->index();
            $table->jsonb('resumen');
            $table->timestampsTz();
            $table->unique(['extraccion_id', 'venta_id']);
            $table->index(['extraccion_id', 'estado']);
        });

        Schema::table('ventas', function (Blueprint $table) {
            $table->index(['local_id', 'venta_fecha'], 'ventas_local_fecha_index');
        });

        // Una sola extracción maestra evita que rangos solapados compitan por las mismas ventas.
        DB::statement("CREATE UNIQUE INDEX venta_extracciones_una_activa ON venta_extracciones ((1)) WHERE estado IN ('pendiente', 'planificando', 'en_progreso')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS venta_extracciones_una_activa');
        Schema::table('ventas', fn (Blueprint $table) => $table->dropIndex('ventas_local_fecha_index'));
        Schema::dropIfExists('venta_extraccion_ventas');
        Schema::dropIfExists('venta_extraccion_paginas');
        Schema::table('venta_extracciones', function (Blueprint $table) {
            $table->dropColumn(['paginas_total', 'paginas_procesadas']);
        });
    }
};
