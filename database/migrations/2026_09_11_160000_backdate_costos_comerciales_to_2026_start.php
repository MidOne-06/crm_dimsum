<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Los costos iniciales cargados para indicadores corresponden a todo el
     * ejercicio 2026. La carga original los dejó con fecha 01/09/2026, lo que
     * impedía costear las ventas históricas de enero a agosto.
     */
    public function up(): void
    {
        DB::table('productos_comerciales_costos')
            ->whereDate('vigente_desde', '2026-09-01')
            ->update([
                'vigente_desde' => '2026-01-01',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // No se revierte: después de publicar, un costo puede haber sido
        // editado por negocio y restaurar una fecha fija dañaría su historial.
    }
};
