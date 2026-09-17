<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produccion_diaria_salidas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cierre_id')->constrained('produccion_diaria_cierres')->cascadeOnDelete();
            $table->foreignId('producto_id')->nullable()->constrained('produccion_productos')->nullOnDelete();
            $table->string('item_id');
            $table->string('item_tipo')->nullable();
            $table->string('item_codigo')->nullable();
            $table->string('item_nombre');
            $table->string('unidad')->nullable();
            $table->decimal('cantidad', 14, 4);
            $table->string('destino')->default('despacho'); // despacho | merma | ajuste | otro
            $table->text('nota')->nullable();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['cierre_id', 'item_id'], 'produccion_salidas_cierre_item_idx');
        });

        // Salidas_hoy queda en el detalle del cierre, igual que producido_hoy,
        // para que el histórico de un cierre ya cerrado no dependa de volver
        // a sumar produccion_diaria_salidas -- mismo criterio ya usado para
        // producido_hoy en la migración original de este módulo.
        Schema::table('produccion_diaria_detalles', function (Blueprint $table): void {
            $table->decimal('salidas_hoy', 14, 4)->default(0)->after('producido_hoy');
        });
    }

    public function down(): void
    {
        Schema::table('produccion_diaria_detalles', function (Blueprint $table): void {
            $table->dropColumn('salidas_hoy');
        });
        Schema::dropIfExists('produccion_diaria_salidas');
    }
};
