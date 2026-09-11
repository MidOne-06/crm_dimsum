<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock de seguridad -- pedido explícito del usuario (2026-09-11): el
 * promedio histórico es correcto EN PROMEDIO, pero hay semanas reales con
 * 20-30% más venta de lo normal. En vez de estirar a mano la hora de
 * llegada asumida (mezclaría un dato operativo con una decisión de
 * negocio), se agrega un colchón estadístico basado en la variabilidad
 * REAL de cada producto/local: `stock_seguridad = 1.65 * desviación
 * estándar de las mismas semanas ya usadas para el promedio` (1.65 =
 * nivel de servicio ~95%, elegido explícitamente por el usuario entre
 * 90/95/98%). Un producto volátil (ventas 88-210 entre semanas) recibe
 * bastante colchón; uno estable (39-43) casi no se toca -- a diferencia de
 * un "+20% fijo" (el "Ajuste %" que ya existe), que infla parejo sin
 * distinguir quién de verdad lo necesita.
 *
 * No hace falta ninguna data nueva: la desviación se calcula sobre el
 * mismo array de hasta 10 semanas que `calcularParaFecha()` ya trae de
 * Kardex para el promedio -- ver DirectivaTransferenciaService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('directiva_transferencia_sugerencias', function (Blueprint $table): void {
            $table->decimal('desviacion_estandar', 14, 4)->default(0)->after('demanda_ventana1');
        });
    }

    public function down(): void
    {
        Schema::table('directiva_transferencia_sugerencias', function (Blueprint $table): void {
            $table->dropColumn('desviacion_estandar');
        });
    }
};
