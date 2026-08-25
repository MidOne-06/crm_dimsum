<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opm_producto_candidatos', function (Blueprint $table) {
            $table->text('consulta_normalizada')->default('')->after('ejecucion_id');
            $table->index(['ejecucion_id', 'consulta_normalizada'], 'opm_candidatos_consulta_index');
        });
    }

    public function down(): void
    {
        Schema::table('opm_producto_candidatos', function (Blueprint $table) {
            $table->dropIndex('opm_candidatos_consulta_index');
            $table->dropColumn('consulta_normalizada');
        });
    }
};
