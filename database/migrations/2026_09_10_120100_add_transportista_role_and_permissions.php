<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rol `transportista` + 3 permisos del módulo de entregas (2026-09-10).
 * - `entregas.registrar`: el transportista marca "despacho entregado" (móvil).
 * - `entregas-config.manage`: admin asigna titular/suplente y carga ausencias.
 * - `entregas.historico.view`: ver el histórico de entregas y los promedios.
 *
 * El rol `transportista` arranca solo con `entregas.registrar` -- un
 * transportista no ve nada más del CRM. Los otros 2 permisos van a
 * `superadministrador` (que igual los tiene todos por ser superadmin, pero
 * se dejan mapeados explícitos como el resto del catálogo).
 */
return new class extends Migration
{
    private array $permisos = [
        ['name' => 'Registrar entrega de despacho', 'slug' => 'entregas.registrar', 'module' => 'Entregas'],
        ['name' => 'Configurar transportistas y ausencias', 'slug' => 'entregas-config.manage', 'module' => 'Entregas'],
        ['name' => 'Ver histórico de entregas', 'slug' => 'entregas.historico.view', 'module' => 'Entregas'],
    ];

    public function up(): void
    {
        foreach ($this->permisos as $permiso) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $permiso['slug']],
                [...$permiso, 'is_system' => true, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        // Rol transportista
        DB::table('roles')->updateOrInsert(
            ['slug' => 'transportista'],
            ['name' => 'Transportista', 'is_system' => true, 'created_at' => now(), 'updated_at' => now()],
        );
        $transportistaId = DB::table('roles')->where('slug', 'transportista')->value('id');
        $registrarId = DB::table('permissions')->where('slug', 'entregas.registrar')->value('id');
        if ($transportistaId && $registrarId) {
            DB::table('permission_role')->insertOrIgnore(['role_id' => $transportistaId, 'permission_id' => $registrarId]);
        }

        // superadministrador: los 3
        if ($superId = DB::table('roles')->where('slug', 'superadministrador')->value('id')) {
            foreach ($this->permisos as $permiso) {
                if ($pid = DB::table('permissions')->where('slug', $permiso['slug'])->value('id')) {
                    DB::table('permission_role')->insertOrIgnore(['role_id' => $superId, 'permission_id' => $pid]);
                }
            }
        }
    }

    public function down(): void
    {
        $slugs = array_column($this->permisos, 'slug');
        $ids = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('slug', $slugs)->delete();

        if ($transportistaId = DB::table('roles')->where('slug', 'transportista')->value('id')) {
            DB::table('permission_role')->where('role_id', $transportistaId)->delete();
            DB::table('role_user')->where('role_id', $transportistaId)->delete();
            DB::table('roles')->where('id', $transportistaId)->delete();
        }
    }
};
