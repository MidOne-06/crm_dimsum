<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('permissions')->updateOrInsert(
            ['slug' => 'branding.manage'],
            [
                'name' => 'Gestionar identidad visual',
                'module' => 'Seguridad',
                'is_system' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        $permissionId = DB::table('permissions')->where('slug', 'branding.manage')->value('id');
        $roleId = DB::table('roles')->where('slug', 'superadministrador')->value('id');

        if ($permissionId && $roleId) {
            DB::table('permission_role')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => $roleId,
            ]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', 'branding.manage')->value('id');

        if ($permissionId) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
