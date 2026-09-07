<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Auditoría de permisos (07/09/2026): estos 2 slugs no los consulta
 * ninguna pantalla ni acción del código actual.
 * - kardex.analisis-ventas.view: permiso original de la pantalla de
 *   "análisis de descargas por venta", de antes de que se renombrara a
 *   kardex.analisis-descargas.view -- quedó huérfano desde entonces.
 * - guias-internas.workflow: se creó junto con guias-internas.descargar
 *   (2026-08-31) para una vista de "workflow" de guía interna que nunca
 *   se llegó a construir.
 * Se guarda el `name`/`module` original en `down()` por si hace falta
 * revertir.
 */
return new class extends Migration
{
    private array $orphans = [
        ['name' => 'Ver análisis de descargas por venta', 'slug' => 'kardex.analisis-ventas.view', 'module' => 'Kardex'],
        ['name' => 'Ver workflow de guía interna', 'slug' => 'guias-internas.workflow', 'module' => 'Guías internas'],
    ];

    public function up(): void
    {
        $ids = DB::table('permissions')->whereIn('slug', array_column($this->orphans, 'slug'))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }

    public function down(): void
    {
        $now = now();
        foreach ($this->orphans as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $permission['slug']],
                [...$permission, 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }
};
