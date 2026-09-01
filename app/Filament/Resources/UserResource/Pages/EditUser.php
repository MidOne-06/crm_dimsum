<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var User $user */
        $user = $this->record;
        $data['local_scope'] = $user->local_scope ?: ($user->locals()->exists() ? 'selected' : 'all');
        $data['local_ids'] = $user->locals()->pluck('local_id')->all();

        return $data;
    }

    /**
     * Mismo chequeo que CreateUser::mutateFormDataBeforeCreate() -- quien no
     * es superadministrador no puede otorgar ese rol vía el payload de
     * Livewire. Si el registro YA lo tenía, se conserva en vez de quitarlo:
     * de lo contrario, un editor sin ese rol que guarde cualquier otro
     * cambio (nombre, contraseña) sobre un superadministrador existente lo
     * degradaría como efecto secundario silencioso.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $superadminId = Role::where('slug', 'superadministrador')->value('id');
        $actor = auth()->user();
        $actorIsSuperadmin = $actor?->isPanelAdministrator() || $actor?->roles()->where('slug', 'superadministrador')->exists();

        if ($superadminId && ! $actorIsSuperadmin) {
            /** @var User $record */
            $record = $this->record;
            $recordHadIt = $record->roles()->where('slug', 'superadministrador')->exists();

            $data['roles'] = array_values(array_diff($data['roles'] ?? [], [$superadminId]));

            if ($recordHadIt) {
                $data['roles'][] = $superadminId;
            }
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $this->syncLocals();
    }

    private function syncLocals(): void
    {
        /** @var User $user */
        $user = $this->record;
        $localIds = ($this->data['local_scope'] ?? 'all') === 'selected'
            ? $this->data['local_ids'] ?? []
            : [];
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
