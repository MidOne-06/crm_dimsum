<?php

namespace App\Filament\Resources\ProductoTaperResource\Pages;

use App\Filament\Resources\ProductoTaperResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;

class ListProductoTapers extends ListRecords
{
    protected static string $resource = ProductoTaperResource::class;
    protected function getHeaderActions(): array { return [CreateAction::make()->label('Nueva capacidad')->modalWidth('5xl')->stickyModalHeader()->stickyModalFooter()->modalSubmitActionLabel('Guardar')->modalCancelActionLabel('Cancelar')]; }

    /** Mismo fix que Permisos: sin esto, clicar cualquier celda abre Editar. */
    protected function makeTable(): Table
    {
        return parent::makeTable()->recordAction(null)->recordUrl(null);
    }
}
