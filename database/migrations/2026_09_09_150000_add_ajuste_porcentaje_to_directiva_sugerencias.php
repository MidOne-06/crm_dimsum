<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wizard "Iniciar Directiva de Transferencia" (2026-09-09, pedido explícito
 * del usuario): además de elegir alcance de locales y registrar excepciones
 * de "Días sin DT" antes de calcular, permite aplicar un % de ajuste
 * dinámico sobre la cantidad sugerida (ej. +10%), a un local puntual o a
 * todos -- pensado para compensar de forma manual algo que el modelo
 * todavía no captura solo (una promoción, un evento puntual, etc.), sin
 * tener que esperar a que el histórico de ventas lo refleje.
 *
 * El ajuste se aplica DESPUÉS de la fórmula real (`cantidad_bruta`, ya
 * auditada y verificada) para no ensuciar esa trazabilidad -- se guarda
 * aparte, en dos columnas nuevas: `porcentaje_ajuste_aplicado` (el % que se
 * usó, 0 si no se aplicó ninguno) y `cantidad_bruta_ajustada` (cantidad_bruta
 * * (1 + %/100), el valor que de verdad se redondea al múltiplo para sacar
 * `cantidad_sugerida`). `cantidad_bruta` sigue siendo el número "limpio" sin
 * ajuste, tal como ya lo audita el usuario hoy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('directiva_transferencia_sugerencias', function (Blueprint $table): void {
            $table->decimal('porcentaje_ajuste_aplicado', 6, 2)->default(0)->after('cantidad_bruta');
            $table->decimal('cantidad_bruta_ajustada', 14, 4)->nullable()->after('porcentaje_ajuste_aplicado');
        });
    }

    public function down(): void
    {
        Schema::table('directiva_transferencia_sugerencias', function (Blueprint $table): void {
            $table->dropColumn(['porcentaje_ajuste_aplicado', 'cantidad_bruta_ajustada']);
        });
    }
};
