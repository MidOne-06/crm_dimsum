<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')
            ->where('slug', 'branding.manage')
            ->update([
                'name' => 'Gestionar apariencia',
                'module' => 'Apariencia',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('permissions')
            ->where('slug', 'branding.manage')
            ->update([
                'name' => 'Gestionar identidad visual',
                'module' => 'Seguridad',
                'updated_at' => now(),
            ]);
    }
};
