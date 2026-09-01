<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::query()->where('slug', 'salidas-stock.crear')->firstOrFail();

        $role = Role::query()->updateOrCreate(
            ['slug' => 'terminal'],
            ['name' => 'Terminal', 'is_system' => true],
        );

        // El rol solo puede registrar nuevas salidas: no consulta historial,
        // detalle, stock, reportes ni otros módulos del panel.
        $role->permissions()->sync([$permission->id]);
    }

    public function down(): void
    {
        // Se preservan las asignaciones existentes si alguna vez se revierte.
    }
};
