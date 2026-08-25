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
        if (Schema::hasTable('opm_detalles')) {
            return;
        }

        Schema::create('opm_detalles', function (Blueprint $table) {
            $table->string('detail_key')->primary();
            $table->string('cod_estab')->nullable();
            $table->integer('cod_prod_e')->nullable();
            $table->decimal('precio1', 12, 2)->nullable();
            $table->decimal('precio2', 12, 2)->nullable();
            $table->text('nombre_producto')->nullable();
            $table->string('pais_fabricacion')->nullable();
            $table->string('registro_sanitario')->nullable();
            $table->string('condicion_venta')->nullable();
            $table->string('tipo_producto')->nullable();
            $table->string('nombre_titular')->nullable();
            $table->string('nombre_fabricante')->nullable();
            $table->text('presentacion')->nullable();
            $table->string('laboratorio')->nullable();
            $table->string('director_tecnico')->nullable();
            $table->string('nombre_comercial')->nullable();
            $table->string('telefono')->nullable();
            $table->string('email')->nullable();
            $table->string('ruc')->nullable();
            $table->text('direccion')->nullable();
            $table->string('departamento')->nullable();
            $table->string('provincia')->nullable();
            $table->string('distrito')->nullable();
            $table->text('horario_atencion')->nullable();
            $table->string('ubigeo')->nullable();
            $table->string('cat_codigo')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opm_detalles');
    }
};
