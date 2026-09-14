<?php

namespace App\Filament\Resources\ProduccionProductoResource\Pages;

use App\Filament\Resources\ProduccionProductoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;

class ListProduccionProductos extends ListRecords
{
    protected static string $resource = ProduccionProductoResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nuevo producto')->modalWidth('5xl')->modalSubmitActionLabel('Guardar')->modalCancelActionLabel('Cancelar')
            ->using(fn (array $data) => ProduccionProductoResource::crearDesdeRestaurant($data))];
    }

    protected function makeTable(): Table
    {
        return parent::makeTable()->recordAction(null)->recordUrl(null);
    }
}
