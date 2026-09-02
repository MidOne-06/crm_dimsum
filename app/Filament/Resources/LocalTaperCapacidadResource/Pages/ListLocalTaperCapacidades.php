<?php

namespace App\Filament\Resources\LocalTaperCapacidadResource\Pages;

use App\Filament\Resources\LocalTaperCapacidadResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLocalTaperCapacidades extends ListRecords
{
    protected static string $resource = LocalTaperCapacidadResource::class;
    protected function getHeaderActions(): array { return [CreateAction::make()->label('Nueva capacidad')->modalWidth('5xl')->stickyModalHeader()->stickyModalFooter()->modalSubmitActionLabel('Guardar')->modalCancelActionLabel('Cancelar')]; }
}
