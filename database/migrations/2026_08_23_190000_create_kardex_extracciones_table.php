<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kardex_extracciones')) {
            return;
        }

        Schema::create('kardex_extracciones', function (Blueprint $table) {
            $table->id();
            $table->string('estado')->default('pendiente')->index();
            $table->jsonb('filtros')->nullable();
            $table->integer('locales_total')->nullable();
            $table->integer('locales_procesados')->default(0);
            $table->integer('locales_fallidos')->default(0);
            $table->integer('movimientos_guardados')->default(0);
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
        Schema::dropIfExists('kardex_extracciones');
    }
};
