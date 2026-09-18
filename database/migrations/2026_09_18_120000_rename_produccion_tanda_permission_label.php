<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')
            ->where('slug', 'produccion-diaria.registrar-tanda')
            ->update([
                'name' => 'Registrar bashes de producción',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('permissions')
            ->where('slug', 'produccion-diaria.registrar-tanda')
            ->update([
                'name' => 'Registrar tandas de producción',
                'updated_at' => now(),
            ]);
    }
};
