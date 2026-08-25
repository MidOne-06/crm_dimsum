<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bug: opm_precios.id y opm_detalles.detail_key usaban "codProdE|codEstab"
 * sin incluir parametro_id, causando colisiones entre parametros distintos.
 *
 * Fix: los PKs ahora serán "parametroId|codProdE|codEstab".
 * La data con el formato antiguo se descarta (los parametros quedan como pendientes).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('TRUNCATE TABLE opm_detalles CASCADE');
        DB::statement('TRUNCATE TABLE opm_precios  CASCADE');
        DB::statement('TRUNCATE TABLE opm_productos CASCADE');

        DB::table('opm_parametros')->update([
            'estado'          => 'pendiente',
            'total_productos' => 0,
            'total_precios'   => 0,
            'total_detalles'  => 0,
            'ejecutado_at'    => null,
        ]);
    }

    public function down(): void {}
};
