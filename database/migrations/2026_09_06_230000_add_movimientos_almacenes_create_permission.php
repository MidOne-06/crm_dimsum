<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permission = ['name' => 'Crear movimientos entre almacenes', 'slug' => 'movimientos-almacenes.crear', 'module' => 'Movimientos entre almacenes', 'is_system' => true, 'created_at' => now(), 'updated_at' => now()];
        DB::table('permissions')->updateOrInsert(['slug' => $permission['slug']], $permission);
        if ($roleId = DB::table('roles')->where('slug', 'superadministrador')->value('id')) {
            $permissionId = DB::table('permissions')->where('slug', $permission['slug'])->value('id');
            if ($permissionId) DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('slug', 'movimientos-almacenes.crear')->value('id');
        if ($id) DB::table('permission_role')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('slug', 'movimientos-almacenes.crear')->delete();
    }
};
