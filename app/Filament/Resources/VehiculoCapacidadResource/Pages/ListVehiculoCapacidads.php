<?php

namespace App\Filament\Resources\VehiculoCapacidadResource\Pages;

use App\Filament\Resources\VehiculoCapacidadResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;

class ListVehiculoCapacidads extends ListRecords
{
    protected static string $resource = VehiculoCapacidadResource::class;
    protected function getHeaderActions(): array { return [CreateAction::make()->label('Nuevo vehículo')->modalWidth('5xl')->stickyModalHeader()->stickyModalFooter()->modalSubmitActionLabel('Guardar')->modalCancelActionLabel('Cancelar')]; }

    /** Mismo fix que Permisos: sin esto, clicar cualquier celda abre Editar. */
    protected function makeTable(): Table
    {
        return parent::makeTable()->recordAction(null)->recordUrl(null);
    }
}
