<?php

namespace App\Filament\Resources\LocalTransportistaResource\Pages;

use App\Filament\Resources\LocalTransportistaResource;
use App\Filament\Resources\TransportistaResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;

class ListLocalTransportistas extends ListRecords
{
    protected static string $resource = LocalTransportistaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('transportistas')
                ->label('Transportistas')
                ->icon('heroicon-o-identification')
                ->color('gray')
                ->url(fn (): string => TransportistaResource::getUrl()),
            CreateAction::make()->label('Nueva asignación')->modalWidth('lg')->modalSubmitActionLabel('Guardar')->modalCancelActionLabel('Cancelar'),
        ];
    }

    /** Mismo fix que Permisos: sin esto, clicar cualquier celda abre Editar. */
    protected function makeTable(): Table
    {
        return parent::makeTable()->recordAction(null)->recordUrl(null);
    }
}
