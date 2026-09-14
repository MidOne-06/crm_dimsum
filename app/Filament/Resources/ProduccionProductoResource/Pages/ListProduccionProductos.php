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
        return [CreateAction::make()->label('Nuevo producto')->modalWidth('5xl')->stickyModalHeader()->stickyModalFooter()->modalSubmitActionLabel('Guardar')->modalCancelActionLabel('Cancelar')];
    }

    protected function makeTable(): Table
    {
        return parent::makeTable()->recordAction(null)->recordUrl(null);
    }
}
