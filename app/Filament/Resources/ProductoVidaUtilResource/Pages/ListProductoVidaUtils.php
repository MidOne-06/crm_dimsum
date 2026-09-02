<?php

namespace App\Filament\Resources\ProductoVidaUtilResource\Pages;

use App\Filament\Resources\ProductoVidaUtilResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProductoVidaUtils extends ListRecords
{
    protected static string $resource = ProductoVidaUtilResource::class;
    protected function getHeaderActions(): array { return [CreateAction::make()->label('Nueva vida útil')->modalWidth('5xl')->stickyModalHeader()->stickyModalFooter()->modalSubmitActionLabel('Guardar')->modalCancelActionLabel('Cancelar')]; }
}
