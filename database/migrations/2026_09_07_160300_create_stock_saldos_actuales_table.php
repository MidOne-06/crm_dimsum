<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla materializada del saldo en tiempo real por local x ítem. Se
 * recalcula por completo (nunca de forma incremental) cada vez que
 * termina una extracción real de Kardex para ese local -- ver
 * App\Jobs\RecalcularSaldoStockJob y el hook en ProcesarLocalKardexJob.
 * Recalcular desde cero (stock inicial + ajustes + SUM(entrada)-SUM(salida)
 * de Kardex desde fecha_carga) en vez de ir sumando deltas incrementales
 * es deliberado: si alguna vez hay que re-extraer Kardex por un error
 * retroactivo, este diseño se autocorrige solo, sin duplicar ni perder
 * movimientos.
 *
 * Saldo negativo NO se pisa a 0 -- es una señal real de un problema de
 * datos (merma no registrada, mal motivo, etc.) que se debe poder ver y
 * auditar, no ocultar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_saldos_actuales', function (Blueprint $table): void {
            $table->id();
            $table->string('local_id');
            $table->string('local_nombre')->nullable();
            $table->string('item_id');
            $table->string('item_tipo')->nullable();
            $table->string('item_codigo')->nullable();
            $table->string('item_nombre')->nullable();
            $table->string('unidad')->nullable();
            $table->decimal('cantidad_inicial', 14, 4)->default(0);
            $table->decimal('ajustes_acumulados', 14, 4)->default(0);
            $table->decimal('entradas_kardex', 14, 4)->default(0);
            $table->decimal('salidas_kardex', 14, 4)->default(0);
            $table->decimal('saldo', 14, 4)->default(0);
            $table->timestamp('kardex_actualizado_hasta')->nullable();
            $table->timestamp('recalculado_en')->nullable();
            $table->timestamps();

            $table->unique(['local_id', 'item_id', 'item_tipo'], 'stock_saldo_item_unico');
            $table->index('saldo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_saldos_actuales');
    }
};
