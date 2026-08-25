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
        if (Schema::hasTable('opm_precios')) {
            return;
        }

        Schema::create('opm_precios', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('producto_id');
            $table->foreign('producto_id')->references('id')->on('opm_productos')->cascadeOnDelete();
            $table->string('cod_estab');
            $table->integer('cod_prod_e');
            $table->text('nombre_producto')->nullable();
            $table->string('concentracion')->nullable();
            $table->decimal('precio1', 12, 2)->nullable();
            $table->decimal('precio2', 12, 2)->nullable();
            $table->decimal('precio3', 12, 2)->nullable();
            $table->string('nombre_comercial')->nullable();
            $table->string('nom_grupo_ff')->nullable();
            $table->string('setcodigo')->nullable();
            $table->text('direccion')->nullable();
            $table->string('telefono')->nullable();
            $table->string('departamento')->nullable();
            $table->string('provincia')->nullable();
            $table->string('distrito')->nullable();
            $table->string('ubicodigo')->nullable();
            $table->string('fecha')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opm_precios');
    }
};
