<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido explícito del usuario (2026-09-13): poder "pausar" un producto para
 * que deje de salir en el cálculo de la Directiva de Transferencia SIN
 * borrarlo (perdiendo su múltiplo/nota configurados) -- queda pausado hasta
 * que se reactive manualmente, con motivo obligatorio al pausar. Mismo
 * principio que LocalActivoOverride, pero acá no hace falta un "automático"
 * (no hay señal de Kardex para decidir si un producto de despacho "debería"
 * estar activo) -- es un toggle manual simple sobre la propia fila.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('producto_presentaciones_despacho', function (Blueprint $table): void {
            $table->boolean('activo')->default(true)->after('nota')->index();
            $table->text('motivo_pausa')->nullable()->after('activo');
            $table->foreignId('pausado_por')->nullable()->after('motivo_pausa')->constrained('users')->nullOnDelete();
            $table->timestamp('pausado_en')->nullable()->after('pausado_por');
        });

        Schema::create('producto_presentacion_despacho_auditorias', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('producto_presentacion_despacho_id')->constrained('producto_presentaciones_despacho')->cascadeOnDelete();
            $table->string('accion');
            $table->text('motivo')->nullable();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_presentacion_despacho_auditorias');
        Schema::table('producto_presentaciones_despacho', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pausado_por');
            $table->dropColumn(['activo', 'motivo_pausa', 'pausado_en']);
        });
    }
};
