<?php

namespace App\Filament\Resources\LocalDiaSinDtResource\Pages;

use App\Filament\Resources\LocalDiaSinDtResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLocalDiasSinDt extends ListRecords
{
    protected static string $resource = LocalDiaSinDtResource::class;
    protected function getHeaderActions(): array { return [CreateAction::make()->label('Nueva excepción')->modalWidth('lg')->modalSubmitActionLabel('Guardar')->modalCancelActionLabel('Cancelar')]; }
}
