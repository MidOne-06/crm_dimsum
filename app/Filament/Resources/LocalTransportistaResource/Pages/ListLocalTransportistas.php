<?php

namespace App\Filament\Resources\LocalTransportistaResource\Pages;

use App\Filament\Resources\LocalTransportistaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLocalTransportistas extends ListRecords
{
    protected static string $resource = LocalTransportistaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nueva asignación')->modalWidth('lg')->modalSubmitActionLabel('Guardar')->modalCancelActionLabel('Cancelar'),
        ];
    }
}
