<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branding_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('brand_name')->default('OPM DIGEMID');
            $table->string('logo_path')->nullable();
            $table->timestamps();
        });

        DB::table('branding_settings')->insert([
            'id' => 1,
            'brand_name' => 'OPM DIGEMID',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('branding_settings');
    }
};
