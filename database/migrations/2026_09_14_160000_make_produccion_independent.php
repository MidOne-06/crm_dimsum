<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $permissions = [
        ['name' => 'Ver catálogo de producción', 'slug' => 'produccion-productos.view', 'module' => 'Producción'],
        ['name' => 'Gestionar catálogo de producción', 'slug' => 'produccion-productos.manage', 'module' => 'Producción'],
    ];

    public function up(): void
    {
        Schema::create('produccion_productos', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo')->nullable()->unique();
            $table->string('nombre');
            $table->string('unidad')->default('UNIDAD');
            $table->boolean('activo')->default(true)->index();
            $table->timestamps();
        });

        Schema::table('produccion_diaria_detalles', function (Blueprint $table): void {
            $table->foreignId('producto_id')->nullable()->after('cierre_id')->constrained('produccion_productos')->nullOnDelete();
        });
        Schema::table('produccion_diaria_tandas', function (Blueprint $table): void {
            $table->foreignId('producto_id')->nullable()->after('cierre_id')->constrained('produccion_productos')->nullOnDelete();
        });

        $now = now();
        foreach ($this->permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $permission['slug']],
                [...$permission, 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            );
        }
        if ($roleId = DB::table('roles')->where('slug', 'superadministrador')->value('id')) {
            foreach (DB::table('permissions')->whereIn('slug', array_column($this->permissions, 'slug'))->pluck('id') as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('produccion_diaria_tandas', function (Blueprint $table): void { $table->dropConstrainedForeignId('producto_id'); });
        Schema::table('produccion_diaria_detalles', function (Blueprint $table): void { $table->dropConstrainedForeignId('producto_id'); });
        Schema::dropIfExists('produccion_productos');
        $ids = DB::table('permissions')->whereIn('slug', array_column($this->permissions, 'slug'))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
