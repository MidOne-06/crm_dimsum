<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opm_ejecuciones', function (Blueprint $table): void {
            $table->text('consulta_producto')->nullable()->after('parametro_id');
            $table->string('modo_extraccion')->default('catalogo_completo')->after('consulta_producto');
        });
    }

    public function down(): void
    {
        Schema::table('opm_ejecuciones', function (Blueprint $table): void {
            $table->dropColumn(['consulta_producto', 'modo_extraccion']);
        });
    }
};
