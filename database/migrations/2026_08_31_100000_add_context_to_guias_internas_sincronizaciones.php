<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guias_internas_sincronizaciones', function (Blueprint $table): void {
            $table->jsonb('filtros')->nullable()->after('estado');
            $table->foreignId('iniciado_por')->nullable()->after('filtros')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('guias_internas_sincronizaciones', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('iniciado_por');
            $table->dropColumn('filtros');
        });
    }
};
