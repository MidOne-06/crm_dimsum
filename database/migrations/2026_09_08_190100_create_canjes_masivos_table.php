<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una fila por corrida de "canjear todo lo filtrado". Dos fases separadas a
 * propósito -- previsualizar (solo lee Restaurant, no escribe nada) y
 * confirmar (recién ahí registra movimientos reales) -- para que el usuario
 * vea cuántas guías/movimientos/soles implica ANTES de poder tocar el botón
 * que sí escribe. `resultado` guarda el detalle completo (guías excluidas y
 * por qué, ids de los movimientos realmente creados, fallos por lote) para
 * poder auditar cualquier corrida después, no solo el resumen numérico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canjes_masivos', function (Blueprint $table): void {
            $table->id();
            $table->string('estado')->default('previsualizando')->index(); // previsualizando|listo|confirmando|completado|completado_con_errores|fallido
            $table->jsonb('filtros'); // mismos filtros que la grilla de Guías internas
            $table->unsignedInteger('total_guias_filtro')->default(0);
            $table->unsignedInteger('total_guias_procesables')->default(0);
            $table->unsignedInteger('total_guias_excluidas')->default(0);
            $table->unsignedInteger('total_grupos_estimados')->default(0);
            $table->decimal('total_valorizado_estimado', 18, 4)->default(0);
            $table->unsignedInteger('total_guias_confirmadas')->default(0);
            $table->unsignedInteger('total_guias_fallidas')->default(0);
            $table->unsignedInteger('total_movimientos_creados')->default(0);
            $table->jsonb('resultado')->nullable();
            $table->text('mensaje_error')->nullable();
            $table->foreignId('iniciado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('previsualizado_en')->nullable();
            $table->timestampTz('confirmado_en')->nullable();
            $table->timestampTz('completado_en')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canjes_masivos');
    }
};
