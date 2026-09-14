<?php

namespace App\Filament\Resources\ProduccionCategoriaResource\Pages;

use App\Filament\Resources\ProduccionCategoriaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;

class ListProduccionCategorias extends ListRecords
{
    protected static string $resource = ProduccionCategoriaResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nueva categoría')->modalWidth('5xl')->stickyModalHeader()->stickyModalFooter()->modalSubmitActionLabel('Guardar')->modalCancelActionLabel('Cancelar')];
    }

    protected function makeTable(): Table
    {
        return parent::makeTable()->recordAction(null)->recordUrl(null);
    }
}
