<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('requerimientos_stock_sincronizaciones', function (Blueprint $table): void {
            $table->id();
            $table->json('filtros');
            $table->string('estado', 40)->default('pendiente')->index();
            $table->foreignId('iniciado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('total_registros')->default(0);
            $table->unsignedInteger('registros_procesados')->default(0);
            $table->unsignedInteger('cabeceras_guardadas')->default(0);
            $table->unsignedInteger('detalles_guardados')->default(0);
            $table->unsignedInteger('errores')->default(0);
            $table->text('mensaje_error')->nullable();
            $table->timestamp('iniciado_en')->nullable();
            $table->timestamp('completado_en')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requerimientos_stock_sincronizaciones');
    }
};
