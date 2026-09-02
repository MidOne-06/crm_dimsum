<?php

namespace App\Filament\Resources\CantidadEstandarArranqueResource\Pages;

use App\Filament\Resources\CantidadEstandarArranqueResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCantidadEstandarArranques extends ListRecords
{
    protected static string $resource = CantidadEstandarArranqueResource::class;
    protected function getHeaderActions(): array { return [CreateAction::make()->label('Nueva cantidad de arranque')->modalWidth('5xl')->stickyModalHeader()->stickyModalFooter()->modalSubmitActionLabel('Guardar')->modalCancelActionLabel('Cancelar')]; }
}
