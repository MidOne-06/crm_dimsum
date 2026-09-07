<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $permissions = [
        ['name' => 'Cargar stock inicial', 'slug' => 'stock-inicial.crear', 'module' => 'Stock Inicial'],
        ['name' => 'Ajustar stock inicial', 'slug' => 'stock-inicial.ajustar', 'module' => 'Stock Inicial'],
        ['name' => 'Ver consolidado de stock en tiempo real', 'slug' => 'stock-inicial.view', 'module' => 'Stock Inicial'],
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

        if ($roleId = DB::table('roles')->where('slug', 'superadministrador')->value('id')) {
            DB::table('permissions')->whereIn('slug', array_column($this->permissions, 'slug'))->pluck('id')->each(
                fn (int $permissionId) => DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]),
            );
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('slug', array_column($this->permissions, 'slug'))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
