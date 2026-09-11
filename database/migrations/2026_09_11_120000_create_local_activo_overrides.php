<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Locales Activos" -- pedido explícito del usuario tras el incidente real
 * del 2026-09-11: una corrida de "Iniciar Directiva de Transferencia" con
 * "Todos, excepto..." usada al revés (se excluyeron los 27 locales activos
 * en vez de los 5 cerrados) dejó cantidades reales de despacho calculadas
 * para tiendas cerradas.
 *
 * Investigado antes de programar: Restaurant SÍ expone un flag propio por
 * local (`local_estado`, `local_esventa`) vía
 * `obtenerLocalesPermitidosParaUsuarioID` -- pero probado en vivo contra
 * los 37 locales reales, los 5 locales que el usuario ya confirmó como
 * cerrados (KM 40, Puntamar, Villa María, Primavera, Metro Chorrillos)
 * siguen marcados `estado=1, es_venta=1` en Restaurant -- es una
 * configuración de catálogo (¿puede este local vender en el POS?), no un
 * indicador de operación real, y nadie lo actualiza cuando un local cierra
 * de baja (Pershing es la única excepción real encontrada, `es_venta=0`).
 * Ese flag NO sirve como fuente de verdad.
 *
 * La señal dinámica confiable que ya existe es la actividad real de Kardex
 * (`DirectivaTransferenciaService::localesConVentaActiva()`, venta de un
 * ítem de despacho en los últimos 3 días) -- viene de datos operativos
 * reales sincronizados cada 30 min, no de una config estática. Esta tabla
 * NO reemplaza esa señal automática -- permite **forzar** el estado de un
 * local puntual cuando el automático no calza con la realidad (ej. un
 * local recién reabierto que todavía no acumula 3 días de venta, o un
 * cierre reciente que Kardex aún no refleja). Sin fila = automático.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('local_activo_overrides', function (Blueprint $table): void {
            $table->id();
            $table->string('local_id')->unique();
            $table->string('local_nombre')->nullable();
            $table->boolean('activo');
            $table->string('motivo', 160)->nullable();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $permission = ['name' => 'Gestionar locales activos (Directiva)', 'slug' => 'directiva-transferencia.locales-activos.manage', 'module' => 'Stock Inicial', 'is_system' => true, 'created_at' => now(), 'updated_at' => now()];
        DB::table('permissions')->updateOrInsert(['slug' => $permission['slug']], $permission);
        if ($roleId = DB::table('roles')->where('slug', 'superadministrador')->value('id')) {
            $permissionId = DB::table('permissions')->where('slug', $permission['slug'])->value('id');
            if ($permissionId) DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('slug', 'directiva-transferencia.locales-activos.manage')->value('id');
        if ($id) DB::table('permission_role')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('slug', 'directiva-transferencia.locales-activos.manage')->delete();

        Schema::dropIfExists('local_activo_overrides');
    }
};
