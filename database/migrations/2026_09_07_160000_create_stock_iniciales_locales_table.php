<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cabecera de la carga única de stock inicial por local -- es el punto de
 * partida (t=0) del saldo en tiempo real. Independiente por completo de
 * `stock_cuadres` (eso es un espejo de Restaurant); esta tabla es 100%
 * nativa del CRM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_iniciales_locales', function (Blueprint $table): void {
            $table->id();
            $table->string('local_id')->unique();
            $table->string('local_nombre')->nullable();
            $table->date('fecha_carga');
            $table->foreignId('cargado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('estado')->default('borrador'); // borrador | confirmado
            $table->timestamp('confirmado_en')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_iniciales_locales');
    }
};
