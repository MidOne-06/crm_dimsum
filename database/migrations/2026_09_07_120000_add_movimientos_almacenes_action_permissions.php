<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Editar/Anular/Descargar en el Listado de movimientos solo dependían de
 * `movimientos-almacenes.view` -- cualquiera con acceso de lectura al
 * listado podía anular o editar un movimiento real en Restaurant, a
 * diferencia de Guías internas, que ya separa `.anular` de `.view`. Se
 * agregan estos 3 permisos siguiendo ese mismo patrón; ver el cambio en
 * app/Filament/Pages/Stock/MovimientosAlmacenes.php que los consume.
 */
return new class extends Migration
{
    private array $permissions = [
        ['name' => 'Editar movimiento entre almacenes', 'slug' => 'movimientos-almacenes.editar', 'module' => 'Movimientos entre almacenes'],
        ['name' => 'Anular movimiento entre almacenes', 'slug' => 'movimientos-almacenes.anular', 'module' => 'Movimientos entre almacenes'],
        ['name' => 'Descargar movimiento entre almacenes en PDF', 'slug' => 'movimientos-almacenes.descargar', 'module' => 'Movimientos entre almacenes'],
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
