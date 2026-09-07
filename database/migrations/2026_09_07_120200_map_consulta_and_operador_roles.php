<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los roles base "Consulta" y "Operador" existían con 0 permisos desde su
 * creación -- nadie terminó de mapearlos. Definidos a pedido del usuario:
 *
 * - Consulta: solo lectura en TODO el sistema (view/ver-detalle/reporte.view).
 *   Se excluyen a propósito crear/editar/anular/exportar/descargar y las
 *   extracciones que además de mostrar la pantalla disparan una sincronización
 *   real contra Restaurant (guias-internas.sincronizar,
 *   requerimientos-stock.reporte.sincronizar, movimientos-almacenes.extraccion
 *   son permisos "todo en uno": la misma acción que abre la pantalla también
 *   permite iniciar la corrida, a diferencia de kardex.extraccion.view y
 *   ventas.extraccion.view, que sí separan ver de iniciar -- esos dos quedan
 *   incluidos porque son lectura real).
 * - Operador: todo lo de Consulta + crear en los módulos operativos del
 *   día a día (Requerimientos, Salidas, Guías internas, Movimientos entre
 *   almacenes, Stock final) -- sin anular, sin editar movimientos, sin
 *   gestión (roles/usuarios/permisos/apariencia/sincronización/tapers).
 */
return new class extends Migration
{
    private array $consulta = [
        'kardex.view', 'kardex.historico.view', 'kardex.promedios-ventas.view',
        'kardex.analisis-descargas.view', 'kardex.consolidado-ventas.view', 'kardex.extraccion.view',
        'guias-internas.view', 'guias-internas.ver-detalle', 'guias-internas.reporte.view',
        'requerimientos-stock.view', 'requerimientos-stock.ver-detalle', 'requerimientos-stock.reporte.view',
        'requerimientos-stock.plantillas.view',
        'salidas-stock.view', 'salidas-stock.ver-detalle',
        'stock.actual.view', 'stock.actual.ver-detalle', 'stock.consolidado.view', 'stock-final.view',
        'movimientos-almacenes.view', 'movimientos-almacenes.reporte.view',
        'ventas.consulta.view', 'ventas.consulta.ver-detalle', 'ventas.historico.view', 'ventas.historico.ver-detalle',
        'ventas.reporte.view', 'ventas.reporte.ver-detalle', 'ventas.extraccion.view',
    ];

    private array $operadorExtra = [
        'requerimientos-stock.crear', 'requerimientos-stock.plantillas.importar',
        'salidas-stock.crear',
        'guias-internas.crear',
        'movimientos-almacenes.crear',
        'stock-final.guardar', 'stock-final.plantilla.usar',
    ];

    public function up(): void
    {
        $consultaRoleId = DB::table('roles')->where('slug', 'consulta')->value('id');
        $operadorRoleId = DB::table('roles')->where('slug', 'operador')->value('id');

        if ($consultaRoleId) {
            $this->assign($consultaRoleId, $this->consulta);
        }

        if ($operadorRoleId) {
            $this->assign($operadorRoleId, [...$this->consulta, ...$this->operadorExtra]);
        }
    }

    public function down(): void
    {
        $consultaRoleId = DB::table('roles')->where('slug', 'consulta')->value('id');
        $operadorRoleId = DB::table('roles')->where('slug', 'operador')->value('id');

        if ($consultaRoleId) {
            DB::table('permission_role')->where('role_id', $consultaRoleId)->delete();
        }

        if ($operadorRoleId) {
            DB::table('permission_role')->where('role_id', $operadorRoleId)->delete();
        }
    }

    /** @param array<int, string> $slugs */
    private function assign(int $roleId, array $slugs): void
    {
        DB::table('permissions')->whereIn('slug', $slugs)->pluck('id')->each(
            fn (int $permissionId) => DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]),
        );
    }
};
