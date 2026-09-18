<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venta_payload_archivos', function (Blueprint $table): void {
            $table->timestampTz('respaldo_externo_en')->nullable()->index()->after('verificado_en');
            $table->string('respaldo_externo_origen')->nullable()->after('respaldo_externo_en');
        });
    }

    public function down(): void
    {
        Schema::table('venta_payload_archivos', function (Blueprint $table): void {
            $table->dropColumn(['respaldo_externo_en', 'respaldo_externo_origen']);
        });
    }
};
