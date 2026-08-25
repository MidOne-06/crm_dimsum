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
        if (Schema::hasTable('opm_productos')) {
            return;
        }

        Schema::create('opm_productos', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->text('nombre_producto');
            $table->string('concentracion')->nullable();
            $table->string('forma')->nullable();
            $table->integer('grupo')->nullable();
            $table->string('cod_grupo_ff')->nullable();
            $table->integer('cant_precios')->default(0);
            $table->decimal('min_precio1', 12, 2)->nullable();
            $table->decimal('max_precio1', 12, 2)->nullable();
            $table->decimal('min_precio2', 12, 2)->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opm_productos');
    }
};
