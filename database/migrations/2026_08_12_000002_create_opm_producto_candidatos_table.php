<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opm_producto_candidatos', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('producto_id');
            $table->foreignId('ejecucion_id')->constrained('opm_ejecuciones')->cascadeOnDelete();
            $table->text('nombre_producto');
            $table->text('nombre_normalizado');
            $table->string('concentracion')->nullable();
            $table->string('forma')->nullable();
            $table->integer('grupo')->nullable();
            $table->string('cod_grupo_ff')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('producto_id')->references('id')->on('opm_productos')->cascadeOnDelete();
            $table->index('producto_id');
            $table->index('ejecucion_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opm_producto_candidatos');
    }
};
