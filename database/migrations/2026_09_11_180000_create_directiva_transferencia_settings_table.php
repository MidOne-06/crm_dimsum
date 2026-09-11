<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nivel de servicio del stock de seguridad, configurable -- pedido explícito
 * del usuario (2026-09-11, barrida de huecos funcionales sobre la fórmula
 * agregada el mismo día): antes de esto, `FACTOR_SERVICIO_95 = 1.65` vivía
 * como constante en `DirectivaTransferenciaSugerencia`, así que cualquier
 * cambio de nivel de servicio exigía tocar código y desplegar. Fila única
 * (mismo patrón que `branding_settings`), editable desde una pantalla nueva
 * de Configuración -- ver `App\Models\DirectivaTransferenciaSetting`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directiva_transferencia_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('nivel_servicio_pct')->default(95);
            $table->decimal('factor_servicio', 6, 4)->default(1.6449);
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('directiva_transferencia_settings')->insert([
            'id' => 1,
            'nivel_servicio_pct' => 95,
            'factor_servicio' => 1.6449,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $permission = ['name' => 'Configurar stock de seguridad (Directiva)', 'slug' => 'directiva-transferencia.configurar', 'module' => 'Stock Inicial', 'is_system' => true, 'created_at' => now(), 'updated_at' => now()];
        DB::table('permissions')->updateOrInsert(['slug' => $permission['slug']], $permission);
        if ($roleId = DB::table('roles')->where('slug', 'superadministrador')->value('id')) {
            $permissionId = DB::table('permissions')->where('slug', $permission['slug'])->value('id');
            if ($permissionId) DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('slug', 'directiva-transferencia.configurar')->value('id');
        if ($id) DB::table('permission_role')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('slug', 'directiva-transferencia.configurar')->delete();

        Schema::dropIfExists('directiva_transferencia_settings');
    }
};
