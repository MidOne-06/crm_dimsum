<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branding_settings', function (Blueprint $table): void {
            $table->string('favicon_path')->nullable()->after('logo_path');
            $table->string('logo_height', 20)->default('2.25rem')->after('favicon_path');
            $table->string('primary_color', 20)->default('#f59e0b')->after('logo_height');
        });
    }

    public function down(): void
    {
        Schema::table('branding_settings', function (Blueprint $table): void {
            $table->dropColumn(['favicon_path', 'logo_height', 'primary_color']);
        });
    }
};
