<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $permissions = [
        ['name' => 'Ver detalle de cuadre (stock actual)', 'slug' => 'stock.actual.ver-detalle', 'module' => 'Stock Actual'],
        ['name' => 'Usar plantilla (carga stock final)', 'slug' => 'stock-final.plantilla.usar', 'module' => 'Stock Actual'],
        ['name' => 'Guardar plantilla (carga stock final)', 'slug' => 'stock-final.plantilla.guardar', 'module' => 'Stock Actual'],
        ['name' => 'Ver detalle de venta (reporte)', 'slug' => 'ventas.reporte.ver-detalle', 'module' => 'Ventas'],
        ['name' => 'Ver detalle de venta (consulta)', 'slug' => 'ventas.consulta.ver-detalle', 'module' => 'Ventas'],
        ['name' => 'Iniciar extracción de ventas', 'slug' => 'ventas.extraccion.iniciar', 'module' => 'Ventas'],
        ['name' => 'Ver detalle de venta (histórico)', 'slug' => 'ventas.historico.ver-detalle', 'module' => 'Ventas'],
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
