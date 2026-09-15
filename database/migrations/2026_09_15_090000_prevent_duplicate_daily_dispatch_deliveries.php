<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'entregas_despacho_local_dia_unique';

    public function up(): void
    {
        if (! Schema::hasTable('entregas_despacho')) {
            return;
        }

        $hasDuplicates = DB::table('entregas_despacho')
            ->selectRaw('local_id, DATE(fecha_hora) as fecha')
            ->groupBy('local_id', DB::raw('DATE(fecha_hora)'))
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicates) {
            throw new RuntimeException(
                'No se puede activar el control de entrega diaria: existen registros duplicados por local y fecha.'
            );
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS '.self::INDEX.' ON entregas_despacho (local_id, (DATE(fecha_hora)))'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
    }
};
