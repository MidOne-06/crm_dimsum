<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Mismo propósito que la migración equivalente de guías internas: permitir cancelar una corrida desde la UI matando su proceso real. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requerimientos_stock_sincronizaciones', function (Blueprint $table): void {
            $table->unsignedInteger('proceso_pid')->nullable()->after('iniciado_por');
        });
    }

    public function down(): void
    {
        Schema::table('requerimientos_stock_sincronizaciones', function (Blueprint $table): void {
            $table->dropColumn('proceso_pid');
        });
    }
};
