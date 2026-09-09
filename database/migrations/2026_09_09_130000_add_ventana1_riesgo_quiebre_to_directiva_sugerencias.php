<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rediseño real de la fórmula, a pedido explícito del usuario, entendido a
 * lo largo de varias rondas de ejemplos numéricos propios el 2026-09-09:
 * lo que se despacha HOY no puede cubrir la demanda de "ahora a mañana"
 * (llega recién mañana) -- tiene que cubrir la demanda de "mañana (cuando
 * llega) a pasado mañana (próxima llegada)". `demanda_promedio` pasa a
 * representar esa ventana extendida completa (ahora -> pasado mañana).
 *
 * `demanda_ventana1` guarda aparte la demanda de SOLO el primer tramo
 * (ahora -> mañana) -- la que tiene que aguantar el stock proyectado
 * actual por sí solo, sin ayuda de lo que se despache hoy (llega tarde
 * para eso). Si esa ventana 1 sola ya supera el stock proyectado,
 * `riesgo_quiebre` queda en true: hay un quiebre real e inevitable ANTES
 * de que llegue la reposición de mañana, que ningún despacho de hoy puede
 * evitar -- el usuario pidió que esto se muestre como aviso aparte, no
 * escondido dentro del número final de cantidad sugerida.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('directiva_transferencia_sugerencias', function (Blueprint $table): void {
            $table->decimal('demanda_ventana1', 14, 4)->default(0)->after('demanda_promedio');
            $table->boolean('riesgo_quiebre')->default(false)->after('demanda_ventana1');
        });
    }

    public function down(): void
    {
        Schema::table('directiva_transferencia_sugerencias', function (Blueprint $table): void {
            $table->dropColumn(['demanda_ventana1', 'riesgo_quiebre']);
        });
    }
};
