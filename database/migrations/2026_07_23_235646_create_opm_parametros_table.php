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
        Schema::create('opm_parametros', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('cod_categoria');
            $table->string('desc_categoria');
            $table->string('cod_tipo');
            $table->string('desc_tipo');
            $table->string('cod_departamento');
            $table->string('desc_departamento');
            $table->string('cod_provincia');
            $table->string('desc_provincia');
            $table->string('cod_distrito');
            $table->string('desc_distrito');
            $table->string('estado')->default('pendiente'); // pendiente | ejecutando | completado | error
            $table->integer('total_productos')->default(0);
            $table->integer('total_precios')->default(0);
            $table->integer('total_detalles')->default(0);
            $table->timestampTz('ejecutado_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('opm_parametros');
    }
};
