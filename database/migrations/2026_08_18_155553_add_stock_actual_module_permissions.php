<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $permissions = [
        ['name' => 'Ver stock consolidado', 'slug' => 'stock.consolidado.view', 'module' => 'Stock Actual'],
        ['name' => 'Ver stock', 'slug' => 'stock.actual.view', 'module' => 'Stock Actual'],
    ];

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->insert(array_map(
            fn (array $permission): array => [...$permission, 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            $this->permissions,
        ));

        $superadminRoleId = DB::table('roles')->where('slug', 'superadministrador')->value('id');
        $newPermissionIds = DB::table('permissions')
            ->whereIn('slug', array_column($this->permissions, 'slug'))
            ->pluck('id');

        if ($superadminRoleId) {
            DB::table('permission_role')->insert(
                $newPermissionIds->map(fn (int $permissionId): array => [
                    'role_id' => $superadminRoleId,
                    'permission_id' => $permissionId,
                ])->all(),
            );
        }
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('slug', array_column($this->permissions, 'slug'))->delete();
    }
};
