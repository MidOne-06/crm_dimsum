<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private array $permissions = [
        ['name' => 'Ver reporte de guías internas', 'slug' => 'guias-internas.reporte.view', 'module' => 'Guías internas'],
        ['name' => 'Exportar reporte de guías internas', 'slug' => 'guias-internas.reporte.exportar', 'module' => 'Guías internas'],
    ];

    public function up(): void
    {
        $now = now();
        foreach ($this->permissions as $permission) {
            DB::table('permissions')->updateOrInsert(['slug' => $permission['slug']], [...$permission, 'is_system' => true, 'created_at' => $now, 'updated_at' => $now]);
        }

        if ($roleId = DB::table('roles')->where('slug', 'superadministrador')->value('id')) {
            DB::table('permissions')->whereIn('slug', array_column($this->permissions, 'slug'))->pluck('id')
                ->each(fn (int $permissionId) => DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]));
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('slug', array_column($this->permissions, 'slug'))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
