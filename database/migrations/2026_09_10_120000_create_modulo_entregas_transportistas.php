<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo de entregas por transportista (2026-09-10, pedido explícito del
 * usuario). Control 100% operativo -- NO toca guías internas ni
 * `recepcionada`, solo sirve para el histórico y el promedio de hora de
 * entrega por local x día de semana. Por ahora ese promedio NO alimenta
 * el DT (sigue en 12:00 por defecto).
 *
 * - `local_transportistas`: titular + suplente por local (1 de cada, el
 *   índice único lo garantiza).
 * - `transportista_ausencias`: rango de fechas en que un transportista no
 *   entrega (vacaciones, licencia, etc.). Mientras hay una ausencia
 *   vigente, sus locales de titular pasan al suplente.
 * - `entregas_despacho`: cada "despacho entregado" que marca un
 *   transportista, con la hora exacta, quién (titular o suplente), si fue
 *   reemplazo y por qué, y la foto opcional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('local_transportistas', function (Blueprint $table): void {
            $table->id();
            $table->string('local_id');
            $table->string('local_nombre')->nullable();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('es_suplente')->default(false);
            $table->timestamps();
            // Exactamente 1 titular + 1 suplente por local.
            $table->unique(['local_id', 'es_suplente']);
        });

        Schema::create('transportista_ausencias', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->string('motivo', 120);
            $table->timestamps();
            $table->index(['user_id', 'fecha_inicio', 'fecha_fin']);
        });

        Schema::create('entregas_despacho', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('local_id');
            $table->string('local_nombre')->nullable();
            $table->timestamp('fecha_hora');
            $table->unsignedTinyInteger('dia_semana'); // 1=lunes ... 7=domingo (ISO)
            $table->string('rol_entrega', 20); // 'titular' | 'suplente'
            $table->boolean('es_reemplazo')->default(false);
            $table->string('motivo_reemplazo', 120)->nullable();
            $table->string('foto_path')->nullable();
            $table->text('observacion')->nullable();
            $table->timestamps();
            $table->index(['local_id', 'fecha_hora']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entregas_despacho');
        Schema::dropIfExists('transportista_ausencias');
        Schema::dropIfExists('local_transportistas');
    }
};
