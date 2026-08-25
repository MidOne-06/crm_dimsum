<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kardex_extraccion_locales')) {
            return;
        }

        // Un reporte de Kardex trae TODOS los almacenes de un local en una sola
        // llamada (almacen_id=-1) -- a diferencia de Ventas, aquí no hace falta
        // paginar: un job por local alcanza.
        Schema::create('kardex_extraccion_locales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('extraccion_id')->constrained('kardex_extracciones')->cascadeOnDelete();
            $table->string('local_id')->index();
            $table->string('local_nombre')->nullable();
            $table->string('estado')->default('pendiente')->index();
            $table->integer('movimientos_guardados')->default(0);
            $table->text('mensaje_error')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['extraccion_id', 'local_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kardex_extraccion_locales');
    }
};
