<?php

namespace App\Filament\Resources\LocalLogisticaConfigResource\Pages;

use App\Filament\Resources\LocalLogisticaConfigResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;

class ListLocalLogisticaConfigs extends ListRecords
{
    protected static string $resource = LocalLogisticaConfigResource::class;
    protected function getHeaderActions(): array { return [CreateAction::make()->label('Nueva configuración de local')->modalWidth('5xl')->stickyModalHeader()->stickyModalFooter()->modalSubmitActionLabel('Guardar')->modalCancelActionLabel('Cancelar')]; }

    /** Mismo fix que Permisos: sin esto, clicar cualquier celda abre Editar. */
    protected function makeTable(): Table
    {
        return parent::makeTable()->recordAction(null)->recordUrl(null);
    }
}
