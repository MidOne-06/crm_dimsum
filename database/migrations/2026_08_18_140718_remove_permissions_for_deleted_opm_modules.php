<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $slugs = [
        'parameters.view',
        'parameters.manage',
        'products.view',
        'prices.view',
        'catalogs.view',
        'digemid.validate',
        'digemid.extract',
        'proxy.manage',
    ];

    public function up(): void
    {
        // Los módulos Parámetros, Productos, Precios, Historial de Catálogos,
        // Valida DIGEMID, Extraer Producto y Configuración Proxy se eliminaron
        // del panel; sus permisos quedarían huérfanos si no se retiran también.
        DB::table('permissions')->whereIn('slug', $this->slugs)->delete();
    }

    public function down(): void
    {
        $now = now();

        DB::table('permissions')->insert(array_map(
            fn (array $permission): array => [...$permission, 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            [
                ['name' => 'Ver parámetros', 'slug' => 'parameters.view', 'module' => 'OPM'],
                ['name' => 'Gestionar parámetros', 'slug' => 'parameters.manage', 'module' => 'OPM'],
                ['name' => 'Ver productos', 'slug' => 'products.view', 'module' => 'OPM'],
                ['name' => 'Ver precios', 'slug' => 'prices.view', 'module' => 'OPM'],
                ['name' => 'Ver catálogos', 'slug' => 'catalogs.view', 'module' => 'OPM'],
                ['name' => 'Validar DIGEMID', 'slug' => 'digemid.validate', 'module' => 'DIGEMID'],
                ['name' => 'Extraer producto DIGEMID', 'slug' => 'digemid.extract', 'module' => 'DIGEMID'],
                ['name' => 'Configurar proxy', 'slug' => 'proxy.manage', 'module' => 'DIGEMID'],
            ],
        ));
    }
};
