<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $emails = collect(explode(',', (string) env('FILAMENT_ADMIN_EMAILS', '')))
            ->map(fn (string $email): string => strtolower(trim($email)))
            ->filter()
            ->values();

        if ($emails->isEmpty()) {
            return;
        }

        $roleId = DB::table('roles')->where('slug', 'superadministrador')->value('id');

        if (! $roleId) {
            return;
        }

        DB::table('users')
            ->whereIn(DB::raw('lower(email)'), $emails)
            ->pluck('id')
            ->each(fn (int $userId) => DB::table('role_user')->insertOrIgnore([
                'role_id' => $roleId,
                'user_id' => $userId,
            ]));
    }

    public function down(): void
    {
        // La asignación es inicial y no se revierte automáticamente.
    }
};
