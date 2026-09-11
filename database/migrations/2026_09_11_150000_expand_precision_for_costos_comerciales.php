<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE productos_comerciales_costos ALTER COLUMN costo_unitario TYPE numeric(14, 9)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE productos_comerciales_costos ALTER COLUMN costo_unitario TYPE numeric(14, 6)');
    }
};
