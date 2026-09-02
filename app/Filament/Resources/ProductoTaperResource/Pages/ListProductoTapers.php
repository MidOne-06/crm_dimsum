<?php

namespace App\Filament\Resources\ProductoTaperResource\Pages;

use App\Filament\Resources\ProductoTaperResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProductoTapers extends ListRecords
{
    protected static string $resource = ProductoTaperResource::class;
    protected function getHeaderActions(): array { return [CreateAction::make()->label('Nueva capacidad')->modalWidth('5xl')->stickyModalHeader()->stickyModalFooter()->modalSubmitActionLabel('Guardar')->modalCancelActionLabel('Cancelar')]; }
}
