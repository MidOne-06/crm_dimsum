<?php

namespace App\Filament\Resources\ReglaSustitucionProductoResource\Pages;

use App\Filament\Resources\ReglaSustitucionProductoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;

class ListReglaSustitucionProductos extends ListRecords
{
    protected static string $resource = ReglaSustitucionProductoResource::class;
    protected function getHeaderActions(): array { return [CreateAction::make()->label('Nueva regla de sustitución')->modalWidth('5xl')->stickyModalHeader()->stickyModalFooter()->modalSubmitActionLabel('Guardar')->modalCancelActionLabel('Cancelar')]; }

    /** Mismo fix que Permisos: sin esto, clicar cualquier celda abre Editar. */
    protected function makeTable(): Table
    {
        return parent::makeTable()->recordAction(null)->recordUrl(null);
    }
}
