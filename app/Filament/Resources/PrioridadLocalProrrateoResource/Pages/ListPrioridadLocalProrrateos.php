<?php

namespace App\Filament\Resources\PrioridadLocalProrrateoResource\Pages;

use App\Filament\Resources\PrioridadLocalProrrateoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPrioridadLocalProrrateos extends ListRecords
{
    protected static string $resource = PrioridadLocalProrrateoResource::class;
    protected function getHeaderActions(): array { return [CreateAction::make()->label('Nueva prioridad')->modalWidth('5xl')->stickyModalHeader()->stickyModalFooter()->modalSubmitActionLabel('Guardar')->modalCancelActionLabel('Cancelar')]; }
}
