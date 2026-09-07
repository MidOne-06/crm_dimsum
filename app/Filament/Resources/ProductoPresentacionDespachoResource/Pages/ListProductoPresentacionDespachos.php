<?php

namespace App\Filament\Resources\ProductoPresentacionDespachoResource\Pages;

use App\Filament\Resources\ProductoPresentacionDespachoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;

class ListProductoPresentacionDespachos extends ListRecords
{
    protected static string $resource = ProductoPresentacionDespachoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nueva presentación')
                ->modalWidth('xl')
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->modalSubmitActionLabel('Guardar')
                ->modalCancelActionLabel('Cancelar'),
        ];
    }

    /** Mismo fix que Permisos: sin esto, clicar cualquier celda abre Editar. */
    protected function makeTable(): Table
    {
        return parent::makeTable()
            ->recordAction(null)
            ->recordUrl(null);
    }
}
