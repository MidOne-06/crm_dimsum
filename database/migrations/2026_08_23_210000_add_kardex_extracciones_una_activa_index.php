<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * KardexExtraccion::iniciarExtraccion() hace check-then-act
     * (hayExtraccionEnProgreso() y luego create()) igual que Ventas, pero a
     * diferencia de Ventas nunca se creó el índice único que hace que ese
     * catch(QueryException) sea real -- sin esto, dos usuarios pueden crear
     * dos extracciones activas al mismo tiempo.
     */
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX kardex_extracciones_una_activa ON kardex_extracciones ((1)) WHERE estado IN ('pendiente', 'en_progreso')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS kardex_extracciones_una_activa');
    }
};
