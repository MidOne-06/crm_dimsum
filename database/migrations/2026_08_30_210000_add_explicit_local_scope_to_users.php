<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('local_scope', 16)->default('all')->after('is_active')->index();
        });

        // Conserva el alcance de usuarios creados antes de esta mejora.
        DB::table('users')
            ->whereIn('id', DB::table('user_locals')->select('user_id'))
            ->update(['local_scope' => 'selected']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['local_scope']);
            $table->dropColumn('local_scope');
        });
    }
};
