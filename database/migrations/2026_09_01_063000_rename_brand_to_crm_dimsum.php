<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('branding_settings')
            ->where('id', 1)
            ->update([
                'brand_name' => 'CRM - DIMSUM',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('branding_settings')
            ->where('id', 1)
            ->update([
                'brand_name' => 'CRM DIMSUM',
                'updated_at' => now(),
            ]);
    }
};
