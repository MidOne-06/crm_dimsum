<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->string('module')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('role_user', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'user_id']);
        });

        Schema::create('permission_role', function (Blueprint $table): void {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id']);
        });

        $now = now();

        DB::table('permissions')->insert(array_map(
            fn (array $permission): array => [...$permission, 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            [
                ['name' => 'Gestionar usuarios', 'slug' => 'users.manage', 'module' => 'Seguridad'],
                ['name' => 'Gestionar roles', 'slug' => 'roles.manage', 'module' => 'Seguridad'],
                ['name' => 'Gestionar permisos', 'slug' => 'permissions.manage', 'module' => 'Seguridad'],
            ],
        ));

        DB::table('roles')->insert([
            ['name' => 'Superadministrador', 'slug' => 'superadministrador', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Operador', 'slug' => 'operador', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Consulta', 'slug' => 'consulta', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $roleIds = DB::table('roles')->pluck('id', 'slug');
        $permissionIds = DB::table('permissions')->pluck('id', 'slug');
        $allPermissions = $permissionIds->values()->all();
        $operatorPermissions = [];
        $readerPermissions = [];

        foreach ([
            $roleIds['superadministrador'] => $allPermissions,
            $roleIds['operador'] => $operatorPermissions,
            $roleIds['consulta'] => $readerPermissions,
        ] as $roleId => $assignedPermissions) {
            if ($assignedPermissions !== []) {
                DB::table('permission_role')->insert(array_map(
                    fn (int $permissionId): array => ['role_id' => $roleId, 'permission_id' => $permissionId],
                    $assignedPermissions,
                ));
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });
    }
};
