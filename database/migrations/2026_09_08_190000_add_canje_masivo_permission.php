<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permiso aparte de 'movimientos-almacenes.crear': el canje individual (una
 * guía a la vez, con revisión humana de cada campo) es una cosa; procesar
 * cientos de guías reales de un filtro completo, en tandas automáticas, sin
 * revisión por movimiento, es un riesgo de otro orden -- un administrador
 * puede querer dar el primero sin dar el segundo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = ['name' => 'Canjear masivamente (todo un filtro)', 'slug' => 'movimientos-almacenes.canje-masivo', 'module' => 'Movimientos entre almacenes', 'is_system' => true, 'created_at' => now(), 'updated_at' => now()];
        DB::table('permissions')->updateOrInsert(['slug' => $permission['slug']], $permission);
        if ($roleId = DB::table('roles')->where('slug', 'superadministrador')->value('id')) {
            $permissionId = DB::table('permissions')->where('slug', $permission['slug'])->value('id');
            if ($permissionId) DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('slug', 'movimientos-almacenes.canje-masivo')->value('id');
        if ($id) DB::table('permission_role')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('slug', 'movimientos-almacenes.canje-masivo')->delete();
    }
};
