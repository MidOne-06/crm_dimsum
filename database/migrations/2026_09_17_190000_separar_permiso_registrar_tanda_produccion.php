<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pedido explícito del usuario (2026-09-17): un operario debe poder
 * SOLAMENTE registrar tandas -- ni salidas, ni el cierre físico del día,
 * ni aprobar. Hasta ahora un solo permiso ("produccion-diaria.registrar")
 * controlaba tanda, salida Y cierre físico juntos, así que no había forma
 * de darle a alguien tandas sin darle también todo lo demás. Se agrega un
 * permiso nuevo y más angosto solo para tandas; "produccion-diaria.registrar"
 * queda como el nivel completo (tanda + salida + cierre físico), que ya
 * usan los roles de jefe.
 */
return new class extends Migration
{
    private array $permissions = [
        ['name' => 'Registrar tandas de producción', 'slug' => 'produccion-diaria.registrar-tanda', 'module' => 'Producción'],
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
