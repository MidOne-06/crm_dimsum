<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $permissions = [
        ['name' => 'Exportar stock consolidado', 'slug' => 'stock.consolidado.exportar', 'module' => 'Stock Actual'],
        ['name' => 'Ver salidas de stock', 'slug' => 'salidas-stock.view', 'module' => 'Salidas de Stock'],
        ['name' => 'Ver detalle de salida de stock', 'slug' => 'salidas-stock.ver-detalle', 'module' => 'Salidas de Stock'],
        ['name' => 'Crear salida de stock', 'slug' => 'salidas-stock.crear', 'module' => 'Salidas de Stock'],

        ['name' => 'Ver requerimientos de stock', 'slug' => 'requerimientos-stock.view', 'module' => 'Requerimientos de Stock'],
        ['name' => 'Ver detalle de requerimiento', 'slug' => 'requerimientos-stock.ver-detalle', 'module' => 'Requerimientos de Stock'],
        ['name' => 'Crear requerimiento de stock', 'slug' => 'requerimientos-stock.crear', 'module' => 'Requerimientos de Stock'],
        ['name' => 'Crear solicitud de compra', 'slug' => 'requerimientos-stock.solicitud-compra', 'module' => 'Requerimientos de Stock'],
        ['name' => 'Aprobar requerimiento de stock', 'slug' => 'requerimientos-stock.aprobar', 'module' => 'Requerimientos de Stock'],
        ['name' => 'Rechazar requerimiento de stock', 'slug' => 'requerimientos-stock.rechazar', 'module' => 'Requerimientos de Stock'],
        ['name' => 'Anular requerimiento de stock', 'slug' => 'requerimientos-stock.anular', 'module' => 'Requerimientos de Stock'],
        ['name' => 'Ver plantillas de requerimiento', 'slug' => 'requerimientos-stock.plantillas.view', 'module' => 'Requerimientos de Stock'],
        ['name' => 'Importar plantilla de requerimiento', 'slug' => 'requerimientos-stock.plantillas.importar', 'module' => 'Requerimientos de Stock'],
        ['name' => 'Ver reporte de requerimientos', 'slug' => 'requerimientos-stock.reporte.view', 'module' => 'Requerimientos de Stock'],
        ['name' => 'Sincronizar reporte de requerimientos', 'slug' => 'requerimientos-stock.reporte.sincronizar', 'module' => 'Requerimientos de Stock'],
        ['name' => 'Exportar reporte de requerimientos', 'slug' => 'requerimientos-stock.reporte.exportar', 'module' => 'Requerimientos de Stock'],

        ['name' => 'Descargar kardex', 'slug' => 'kardex.descargar', 'module' => 'Kardex'],
        ['name' => 'Ver análisis de descargas de ventas', 'slug' => 'kardex.analisis-descargas.view', 'module' => 'Kardex'],
        ['name' => 'Ver promedios de venta', 'slug' => 'kardex.promedios-ventas.view', 'module' => 'Kardex'],
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
