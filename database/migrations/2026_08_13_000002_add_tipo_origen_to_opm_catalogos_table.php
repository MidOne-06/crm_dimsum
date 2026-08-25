<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opm_catalogos', function (Blueprint $table): void {
            $table->string('tipo_origen', 20)->default('digemid')->after('sha256');
        });
    }

    public function down(): void
    {
        Schema::table('opm_catalogos', function (Blueprint $table): void {
            $table->dropColumn('tipo_origen');
        });
    }
};
