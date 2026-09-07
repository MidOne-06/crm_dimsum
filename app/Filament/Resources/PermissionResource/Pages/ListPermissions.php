<?php

namespace App\Filament\Resources\PermissionResource\Pages;

use App\Filament\Resources\PermissionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;

class ListPermissions extends ListRecords
{
    protected static string $resource = PermissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nuevo permiso')
                ->modalWidth('2xl')
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->modalSubmitActionLabel('Guardar')
                ->modalCancelActionLabel('Cancelar'),
        ];
    }

    /**
     * `ListRecords::makeTable()` conecta por defecto CUALQUIER celda de la
     * fila a `mountTableAction('edit', ...)` (confirmado en el DOM real:
     * `<button wire:click.prevent.stop="mountTableAction('edit', ...)"
     * class="fi-ta-col">` envolviendo cada celda) -- eso rompe el estándar
     * de modales del proyecto, donde un modal SOLO se abre con un botón
     * explícito (el ícono de lápiz de "Editar permiso"), nunca al hacer
     * clic en cualquier parte de la fila. Ese default se fija DESPUÉS de
     * que corre `PermissionResource::table()`, así que no alcanza con
     * llamar `->recordAction(null)` ahí -- hay que sobreescribir
     * `makeTable()` en la página para que corra al final y gane.
     */
    protected function makeTable(): Table
    {
        return parent::makeTable()
            ->recordAction(null)
            ->recordUrl(null);
    }
}
