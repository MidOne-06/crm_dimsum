<?php

namespace App\Filament\Resources\LocalLogisticaHorarioResource\Pages;

use App\Filament\Resources\LocalLogisticaHorarioResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLocalLogisticaHorarios extends ListRecords
{
    protected static string $resource = LocalLogisticaHorarioResource::class;
    protected function getHeaderActions(): array { return [CreateAction::make()->label('Nuevo horario')->modalWidth('lg')->modalSubmitActionLabel('Guardar')->modalCancelActionLabel('Cancelar')]; }
}
