<?php

namespace App\Filament\Resources\TransportistaAusenciaResource\Pages;

use App\Filament\Resources\TransportistaAusenciaResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTransportistaAusencias extends ListRecords
{
    protected static string $resource = TransportistaAusenciaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('verCalendario')
                ->label('Ver calendario')
                ->icon('heroicon-o-calendar-days')
                ->color('gray')
                ->url(fn (): string => \App\Filament\Pages\Entregas\CalendarioAusencias::getUrl()),
            CreateAction::make()->label('Nueva ausencia')->modalWidth('lg')->modalSubmitActionLabel('Guardar')->modalCancelActionLabel('Cancelar'),
        ];
    }
}
