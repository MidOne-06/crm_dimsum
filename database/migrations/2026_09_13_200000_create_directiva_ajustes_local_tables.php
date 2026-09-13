<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido explícito del usuario (2026-09-13): cada local puede cargar su
 * propio "sugerido" sobre la Directiva de Transferencia -- no una cantidad
 * final libre, sino un ajuste en MÚLTIPLOS del despacho de ese producto
 * (ej. Kai Pi múltiplo=25 -> el local pide +1, +2, -1... nunca +13 sueltos,
 * revalidado en servidor). El administrador aprueba (fila por fila o todas
 * de un local a la vez) o rechaza (con comentario visible para el local) --
 * el local puede seguir corrigiendo mientras la fila siga 'pendiente'.
 *
 * Al aprobar, el delta se suma directo a `cantidad_sugerida` en
 * directiva_transferencia_sugerencias (así todas las pantallas/exports que
 * ya leen esa columna reflejan el ajuste sin tocarlas), y
 * `ajuste_local_unidades` queda como el acumulado visible de cuánto de esa
 * cantidad final vino de un ajuste de local aprobado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('directiva_transferencia_sugerencias', function (Blueprint $table): void {
            $table->decimal('ajuste_local_unidades', 14, 4)->default(0)->after('cantidad_sugerida');
        });

        Schema::create('directiva_ajustes_local_solicitudes', function (Blueprint $table): void {
            $table->id();
            $table->string('local_id');
            $table->string('local_nombre')->nullable();
            $table->date('fecha_despacho');
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['local_id', 'fecha_despacho'], 'directiva_ajuste_local_solicitud_unica');
        });

        Schema::create('directiva_ajustes_local_detalles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('solicitud_id')->constrained('directiva_ajustes_local_solicitudes')->cascadeOnDelete();
            $table->string('item_id');
            $table->string('item_tipo')->nullable();
            $table->string('item_nombre');
            $table->unsignedInteger('multiplo');
            $table->integer('multiplos_solicitados'); // signed: +2, -1, etc.
            $table->decimal('delta_unidades', 14, 4); // multiplos_solicitados * multiplo, para reporte
            $table->text('motivo')->nullable();
            $table->string('estado')->default('pendiente'); // pendiente | aprobado | rechazado
            $table->text('comentario_admin')->nullable();
            $table->foreignId('revisado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revisado_en')->nullable();
            $table->timestamps();

            $table->unique(['solicitud_id', 'item_id', 'item_tipo'], 'directiva_ajuste_local_detalle_unico');
        });

        $permisos = [
            ['name' => 'Cargar sugerido de local (Directiva)', 'slug' => 'directiva-transferencia.ajuste-local.crear', 'module' => 'Stock Inicial'],
            ['name' => 'Aprobar ajustes de local (Directiva)', 'slug' => 'directiva-transferencia.ajuste-local.aprobar', 'module' => 'Stock Inicial'],
        ];
        $now = now();
        foreach ($permisos as $permiso) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $permiso['slug']],
                $permiso + ['is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            );
        }
        if ($roleId = DB::table('roles')->where('slug', 'superadministrador')->value('id')) {
            foreach ($permisos as $permiso) {
                if ($permissionId = DB::table('permissions')->where('slug', $permiso['slug'])->value('id')) {
                    DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('slug', [
            'directiva-transferencia.ajuste-local.crear',
            'directiva-transferencia.ajuste-local.aprobar',
        ])->delete();
        Schema::dropIfExists('directiva_ajustes_local_detalles');
        Schema::dropIfExists('directiva_ajustes_local_solicitudes');
        Schema::table('directiva_transferencia_sugerencias', function (Blueprint $table): void {
            $table->dropColumn('ajuste_local_unidades');
        });
    }
};
