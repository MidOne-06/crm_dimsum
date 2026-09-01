<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('requerimientos_stock_historicos', function (Blueprint $table): void {
            $table->jsonb('payload_restaurant')->nullable()->after('ultima_sincronizacion_error');
        });
        Schema::table('requerimientos_stock_historicos_detalles', function (Blueprint $table): void {
            $table->jsonb('payload_restaurant')->nullable()->after('eliminado_en');
        });
    }

    public function down(): void
    {
        Schema::table('requerimientos_stock_historicos_detalles', function (Blueprint $table): void { $table->dropColumn('payload_restaurant'); });
        Schema::table('requerimientos_stock_historicos', function (Blueprint $table): void { $table->dropColumn('payload_restaurant'); });
    }
};
