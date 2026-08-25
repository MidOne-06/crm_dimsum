<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opm_productos', function (Blueprint $table) {
            $table->text('principio_activo')->nullable()->after('nombre_producto');
        });

        Schema::create('opm_producto_aliases', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('producto_id');
            $table->foreignId('parametro_id')->nullable()->constrained('opm_parametros')->nullOnDelete();
            $table->foreignId('ejecucion_id')->nullable()->constrained('opm_ejecuciones')->cascadeOnDelete();
            $table->text('nombre_catalogo');
            $table->text('nombre_catalogo_normalizado');
            $table->text('principio_activo')->nullable();
            $table->text('principio_activo_normalizado')->nullable();
            $table->string('codigo_catalogo')->nullable();
            $table->string('registro_sanitario')->nullable();
            $table->text('presentacion')->nullable();
            $table->text('fabricante')->nullable();
            $table->text('titular')->nullable();
            $table->string('combinacion_key')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('producto_id')->references('id')->on('opm_productos')->cascadeOnDelete();
            $table->index('producto_id');
            $table->index('ejecucion_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opm_producto_aliases');

        Schema::table('opm_productos', function (Blueprint $table) {
            $table->dropColumn('principio_activo');
        });
    }
};
