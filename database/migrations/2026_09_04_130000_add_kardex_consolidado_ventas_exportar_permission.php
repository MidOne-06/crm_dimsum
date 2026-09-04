<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permission = [
            'name' => 'Exportar consolidado de ventas',
            'slug' => 'kardex.consolidado-ventas.exportar',
            'module' => 'Kardex',
        ];

        DB::table('permissions')->updateOrInsert(
            ['slug' => $permission['slug']],
            [...$permission, 'is_system' => true, 'created_at' => now(), 'updated_at' => now()],
        );

        $roleId = DB::table('roles')->where('slug', 'superadministrador')->value('id');
        $permissionId = DB::table('permissions')->where('slug', $permission['slug'])->value('id');

        if ($roleId && $permissionId) {
            DB::table('permission_role')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', 'kardex.consolidado-ventas.exportar')->value('id');

        if ($permissionId) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
