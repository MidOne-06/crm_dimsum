<?php

namespace App\Filament\Resources\TaperTipoResource\Pages;

use App\Filament\Resources\TaperTipoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;

class ListTaperTipos extends ListRecords
{
    protected static string $resource = TaperTipoResource::class;
    protected function getHeaderActions(): array { return [CreateAction::make()->label('Nuevo tipo de taper')->modalWidth('5xl')->stickyModalHeader()->stickyModalFooter()->modalSubmitActionLabel('Guardar')->modalCancelActionLabel('Cancelar')]; }

    /** Mismo fix que Permisos: sin esto, clicar cualquier celda abre Editar. */
    protected function makeTable(): Table
    {
        return parent::makeTable()->recordAction(null)->recordUrl(null);
    }
}
