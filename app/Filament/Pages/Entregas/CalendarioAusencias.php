<?php

namespace App\Filament\Pages\Entregas;

use App\Filament\Widgets\Entregas\AusenciasCalendarWidget;
use Filament\Actions\Action;
use Filament\Pages\Page;

/**
 * Vista calendario de las ausencias de transportistas (plugin
 * saade/filament-fullcalendar). Solo visualización -- las ausencias se
 * cargan/editan desde "Ausencias de transportistas" (Resource).
 */
class CalendarioAusencias extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';
    protected static ?string $navigationLabel = 'Calendario de ausencias';
    protected static ?string $title = 'Calendario de ausencias de transportistas';
    protected static string|\UnitEnum|null $navigationGroup = 'Entregas';
    protected static ?int $navigationSort = 13;
    protected static ?string $slug = 'entregas/calendario-ausencias';
    protected string $view = 'filament.pages.entregas.calendario-ausencias';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('entregas-config.manage');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('gestionar')
                ->label('Cargar / editar ausencias')
                ->icon('heroicon-o-pencil-square')
                ->url(fn (): string => \App\Filament\Resources\TransportistaAusenciaResource::getUrl()),
        ];
    }

    /** @return array<class-string> */
    protected function getHeaderWidgets(): array
    {
        return [AusenciasCalendarWidget::class];
    }
}
