<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opm_catalogos', function (Blueprint $table): void {
            $table->id();
            $table->string('sha256', 64)->unique();
            $table->text('origen_url');
            $table->text('archivo_fuente');
            $table->text('ruta_indice');
            $table->string('hoja');
            $table->unsignedInteger('total_filas');
            $table->unsignedInteger('total_nombres_unicos');
            $table->unsignedInteger('total_combinaciones_unicas');
            $table->boolean('activo')->default(false)->index();
            $table->timestampTz('obtenido_at');
            $table->timestampTz('verificado_at');
            $table->timestamps();
        });

        Schema::table('opm_ejecuciones', function (Blueprint $table): void {
            $table->foreignId('catalogo_id')->nullable()->after('parametro_id')
                ->constrained('opm_catalogos')->nullOnDelete();
            $table->string('catalogo_hash', 64)->nullable()->after('catalogo_id');
        });
    }

    public function down(): void
    {
        Schema::table('opm_ejecuciones', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('catalogo_id');
            $table->dropColumn('catalogo_hash');
        });
        Schema::dropIfExists('opm_catalogos');
    }
};
