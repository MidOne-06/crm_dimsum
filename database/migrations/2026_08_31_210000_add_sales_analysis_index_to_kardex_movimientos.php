<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // El módulo de promedios consulta exclusivamente salidas por venta del
        // almacén principal. Un índice parcial evita recorrer todos los
        // movimientos de kardex (entradas, ajustes y otros almacenes).
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS kardex_movimientos_sales_analysis_idx
            ON kardex_movimientos (unidad_medida, fecha, local_id, item_id)
            INCLUDE (salida, stock, hora, cod_interno, item_nombre)
            WHERE motivo = 'SALIDA, POR VENTA.'
              AND almacen = 'Almacen Principal'
              AND salida > 0
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS kardex_movimientos_sales_analysis_idx');
    }
};
