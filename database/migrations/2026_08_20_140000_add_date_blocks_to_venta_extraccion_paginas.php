<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venta_extraccion_paginas', function (Blueprint $table) {
            $table->date('fecha_inicio')->nullable()->after('pagina');
            $table->date('fecha_fin')->nullable()->after('fecha_inicio');
            $table->dropUnique(['extraccion_id', 'pagina']);
            $table->unique(['extraccion_id', 'fecha_inicio', 'pagina']);
        });
    }

    public function down(): void
    {
        Schema::table('venta_extraccion_paginas', function (Blueprint $table) {
            $table->dropUnique(['extraccion_id', 'fecha_inicio', 'pagina']);
            $table->dropColumn(['fecha_inicio', 'fecha_fin']);
            $table->unique(['extraccion_id', 'pagina']);
        });
    }
};
