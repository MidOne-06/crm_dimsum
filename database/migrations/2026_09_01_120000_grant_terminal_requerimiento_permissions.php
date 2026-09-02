<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $role = Role::query()->where('slug', 'terminal')->firstOrFail();
        $permissionIds = Permission::query()
            ->whereIn('slug', [
                'requerimientos-stock.crear',
                'requerimientos-stock.plantillas.view',
                'requerimientos-stock.plantillas.importar',
            ])
            ->pluck('id')
            ->all();

        $role->permissions()->syncWithoutDetaching($permissionIds);
    }

    public function down(): void
    {
        // No se eliminan permisos al revertir para no alterar una configuración
        // de roles que haya sido ajustada manualmente por un administrador.
    }
};
