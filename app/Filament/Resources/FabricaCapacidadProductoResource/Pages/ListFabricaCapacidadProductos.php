<?php

namespace App\Filament\Resources\FabricaCapacidadProductoResource\Pages;

use App\Filament\Resources\FabricaCapacidadProductoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFabricaCapacidadProductos extends ListRecords
{
    protected static string $resource = FabricaCapacidadProductoResource::class;
    protected function getHeaderActions(): array { return [CreateAction::make()->label('Nueva capacidad')->modalWidth('5xl')->stickyModalHeader()->stickyModalFooter()->modalSubmitActionLabel('Guardar')->modalCancelActionLabel('Cancelar')]; }
}
