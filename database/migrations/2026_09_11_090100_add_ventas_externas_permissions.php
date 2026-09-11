<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $permissions = [
        ['name' => 'Ver ventas externas', 'slug' => 'ventas-externas.view', 'module' => 'Ventas'],
        ['name' => 'Registrar ventas externas', 'slug' => 'ventas-externas.registrar', 'module' => 'Ventas'],
        ['name' => 'Editar ventas externas', 'slug' => 'ventas-externas.editar', 'module' => 'Ventas'],
        ['name' => 'Anular ventas externas', 'slug' => 'ventas-externas.anular', 'module' => 'Ventas'],
        ['name' => 'Administrar cuotas externas', 'slug' => 'ventas-externas.cuotas', 'module' => 'Ventas'],
    ];

    public function up(): void
    {
        $now = now();
        foreach ($this->permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $permission['slug']],
                [...$permission, 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        $roleId = DB::table('roles')->where('slug', 'superadministrador')->value('id');
        if ($roleId) {
            foreach (DB::table('permissions')->whereIn('slug', array_column($this->permissions, 'slug'))->pluck('id') as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('slug', array_column($this->permissions, 'slug'))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
