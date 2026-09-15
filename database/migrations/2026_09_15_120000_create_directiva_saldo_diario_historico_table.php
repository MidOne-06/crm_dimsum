<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foto del saldo de cierre de cada día, por local x producto de despacho --
 * pedido explícito del usuario (2026-09-15) para poder corregir el sesgo ya
 * documentado del promedio histórico de la Directiva de Transferencia: un
 * día en que el local se quedó sin stock (`saldo_cierre <= 0`) registra
 * "poca venta" en Kardex cuando en realidad hubo demanda insatisfecha que
 * nunca se vendió -- sin este historial, ese día contamina el promedio
 * hacia abajo sin que el sistema pueda saberlo.
 *
 * Deliberadamente NO se puede reconstruir hacia atrás: `stock_saldos_actuales`
 * solo guarda el saldo EN VIVO (recalculado desde `fecha_carga`, que además
 * se pisa en cada rebaseline real del stock inicial), nunca un historial
 * día a día -- esta tabla arranca a acumular datos reales desde el día en
 * que se agregó el comando que la alimenta
 * (`directiva-transferencia:capturar-saldo-diario`), no antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directiva_saldo_diario_historicos', function (Blueprint $table): void {
            $table->id();
            $table->date('fecha');
            $table->string('local_id');
            $table->string('local_nombre')->nullable();
            $table->string('item_id');
            $table->string('item_tipo')->nullable();
            $table->string('item_codigo')->nullable();
            $table->string('item_nombre')->nullable();
            $table->decimal('saldo_cierre', 14, 4)->default(0);
            $table->boolean('quiebre')->default(false);
            $table->timestamp('capturado_en')->nullable();
            $table->timestamps();

            $table->unique(['fecha', 'local_id', 'item_id', 'item_tipo'], 'directiva_saldo_diario_unico');
            $table->index(['local_id', 'item_id', 'item_tipo', 'fecha'], 'directiva_saldo_diario_busqueda');
            $table->index('quiebre');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directiva_saldo_diario_historicos');
    }
};
