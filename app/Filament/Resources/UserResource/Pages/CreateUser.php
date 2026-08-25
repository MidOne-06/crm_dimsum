<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\DB;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * El Select de roles ya oculta la opción "superadministrador" a quien no
     * lo es (ver UserResource::form()), pero esa es solo una restricción de
     * UI -- el payload wire:model igual podría manipularse para incluir ese
     * id. Este es el chequeo real: sin él, cualquier titular de
     * users.manage podría auto-otorgarse control total del sistema.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $superadminId = Role::where('slug', 'superadministrador')->value('id');
        $actor = auth()->user();
        $actorIsSuperadmin = $actor?->isPanelAdministrator() || $actor?->roles()->where('slug', 'superadministrador')->exists();

        if ($superadminId && ! $actorIsSuperadmin) {
            $data['roles'] = array_values(array_diff($data['roles'] ?? [], [$superadminId]));
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->syncLocals();
    }

    private function syncLocals(): void
    {
        /** @var User $user */
        $user = $this->record;
        $localIds = $this->data['local_ids'] ?? [];
        $options = UserResource::localOptions();

        DB::transaction(function () use ($user, $localIds, $options): void {
            $user->locals()->delete();

            foreach ($localIds as $localId) {
                $user->locals()->create([
                    'local_id' => (string) $localId,
                    'local_nombre' => $options[(string) $localId] ?? null,
                ]);
            }
        });
    }
}
