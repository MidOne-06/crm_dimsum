<?php

namespace App\Filament\Resources\PermissionResource\Pages;

use App\Filament\Resources\PermissionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPermissions extends ListRecords
{
    protected static string $resource = PermissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nuevo permiso')
                ->modalWidth('2xl')
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->modalSubmitActionLabel('Guardar')
                ->modalCancelActionLabel('Cancelar'),
        ];
    }
}
