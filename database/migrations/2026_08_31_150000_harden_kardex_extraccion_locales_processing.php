<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kardex_extraccion_locales', function (Blueprint $table): void {
            $table->unsignedSmallInteger('intentos')->default(0)->after('movimientos_guardados');
            $table->timestampTz('procesando_at')->nullable()->after('intentos');
            $table->timestampTz('completado_at')->nullable()->after('procesando_at');
            $table->index(['extraccion_id', 'estado'], 'kardex_extraccion_locales_extraccion_estado_idx');
        });

        Schema::table('kardex_movimientos', function (Blueprint $table): void {
            // La sincronización elimina por local y fecha; este índice evita
            // escaneos completos al reemplazar el detalle de cada local.
            $table->index(['local_id', 'fecha'], 'kardex_movimientos_local_fecha_idx');
        });
    }

    public function down(): void
    {
        Schema::table('kardex_movimientos', function (Blueprint $table): void {
            $table->dropIndex('kardex_movimientos_local_fecha_idx');
        });

        Schema::table('kardex_extraccion_locales', function (Blueprint $table): void {
            $table->dropIndex('kardex_extraccion_locales_extraccion_estado_idx');
            $table->dropColumn(['intentos', 'procesando_at', 'completado_at']);
        });
    }
};
