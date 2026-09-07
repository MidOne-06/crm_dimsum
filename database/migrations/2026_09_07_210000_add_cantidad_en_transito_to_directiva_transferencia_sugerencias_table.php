<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cierra un hueco real de la fórmula: el saldo actual ya refleja las guías
 * internas RECIBIDAS (Kardex las suma sin importar el motivo exacto de
 * entrada), pero no dice nada de las que ya salieron del local origen y
 * siguen con recepcionada=NO -- ese stock está físicamente en camino pero
 * Kardex del destino todavía no se entera. `cantidad_en_transito` guarda,
 * por auditoría, cuánto de esa cantidad pendiente se sumó al saldo antes de
 * calcular la sugerencia (mismo criterio que `saldo_actual`/`demanda_promedio`:
 * cada componente intermedio de la fórmula queda visible, no solo el
 * resultado final).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('directiva_transferencia_sugerencias', function (Blueprint $table): void {
            $table->decimal('cantidad_en_transito', 14, 4)->default(0)->after('saldo_actual');
        });
    }

    public function down(): void
    {
        Schema::table('directiva_transferencia_sugerencias', function (Blueprint $table): void {
            $table->dropColumn('cantidad_en_transito');
        });
    }
};
