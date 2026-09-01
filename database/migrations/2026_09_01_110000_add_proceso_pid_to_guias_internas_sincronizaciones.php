<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guarda el PID del proceso OS que ejecuta cada corrida, para poder
 * cancelarla desde la UI (ExtraccionGuiasInternas::cancelarExtraccion).
 * Sin esto, detener una extracción atascada solo era posible con acceso
 * directo a consola/servidor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guias_internas_sincronizaciones', function (Blueprint $table): void {
            $table->unsignedInteger('proceso_pid')->nullable()->after('iniciado_por');
        });
    }

    public function down(): void
    {
        Schema::table('guias_internas_sincronizaciones', function (Blueprint $table): void {
            $table->dropColumn('proceso_pid');
        });
    }
};
