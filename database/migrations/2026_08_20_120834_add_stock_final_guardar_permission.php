<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $permissions = [
        // Separado de stock-final.view a propósito: este permiso escribe un
        // cuadre manual real en el ERP de Dim Sum (visible en Logística), no
        // es una consulta.
        ['name' => 'Guardar cuadre de stock (carga final)', 'slug' => 'stock-final.guardar', 'module' => 'Stock Actual'],
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
            $ids = DB::table('permissions')->whereIn('slug', array_column($this->permissions, 'slug'))->pluck('id');
            foreach ($ids as $id) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $id]);
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
