<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venta_extraccion_automatizaciones', function (Blueprint $table) {
            $table->id();
            $table->string('estado')->default('pendiente')->index();
            $table->jsonb('segmentos');
            $table->unsignedInteger('indice_actual')->default(0);
            $table->foreignId('extraccion_actual_id')->nullable()->constrained('venta_extracciones')->nullOnDelete();
            $table->foreignId('iniciado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('mensaje_error')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venta_extraccion_automatizaciones');
    }
};
