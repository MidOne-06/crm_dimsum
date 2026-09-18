<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use LogicException;

class BootstrapIsolatedEnvironmentSeeder extends Seeder
{
    public function run(): void
    {
        if (! filter_var(env('ISOLATED_ENVIRONMENT'), FILTER_VALIDATE_BOOLEAN)) {
            throw new LogicException('Este seeder solo puede ejecutarse en un entorno aislado.');
        }

        $email = (string) env('ISOLATED_ADMIN_EMAIL');
        $password = (string) env('ISOLATED_ADMIN_PASSWORD');

        if ($email === '' || $password === '' || str_contains($password, 'CHANGE_ME')) {
            throw new LogicException('Configure las credenciales del administrador aislado antes de iniciar el entorno.');
        }

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => (string) env('ISOLATED_ADMIN_NAME', 'Administrador aislado'),
                'password' => Hash::make($password),
                'is_active' => true,
                'local_scope' => 'all',
            ],
        );

        $role = Role::query()->where('slug', 'superadministrador')->firstOrFail();
        $user->roles()->syncWithoutDetaching([$role->getKey()]);
    }
}
