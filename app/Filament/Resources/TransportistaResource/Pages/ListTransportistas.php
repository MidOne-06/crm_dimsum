<?php

namespace App\Filament\Resources\TransportistaResource\Pages;

use App\Filament\Resources\TransportistaResource;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;

class ListTransportistas extends ListRecords
{
    protected static string $resource = TransportistaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nuevo transportista')
                ->modalWidth('xl')
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->modalSubmitActionLabel('Guardar')
                ->modalCancelActionLabel('Cancelar')
                ->using(fn (array $data): User => TransportistaResource::createTransportista($data)),
        ];
    }

    protected function makeTable(): Table
    {
        return parent::makeTable()
            ->recordAction(null)
            ->recordUrl(null);
    }
}
