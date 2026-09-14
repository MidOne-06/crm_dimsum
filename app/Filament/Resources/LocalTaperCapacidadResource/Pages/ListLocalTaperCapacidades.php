<?php

namespace App\Filament\Resources\LocalTaperCapacidadResource\Pages;

use App\Filament\Resources\LocalTaperCapacidadResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;

class ListLocalTaperCapacidades extends ListRecords
{
    protected static string $resource = LocalTaperCapacidadResource::class;
    protected function getHeaderActions(): array { return [CreateAction::make()->label('Nueva capacidad')->modalWidth('5xl')->stickyModalHeader()->stickyModalFooter()->modalSubmitActionLabel('Guardar')->modalCancelActionLabel('Cancelar')]; }

    /** Mismo fix que Permisos: sin esto, clicar cualquier celda abre Editar. */
    protected function makeTable(): Table
    {
        return parent::makeTable()->recordAction(null)->recordUrl(null);
    }
}
