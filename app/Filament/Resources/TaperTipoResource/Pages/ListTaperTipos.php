<?php

namespace App\Filament\Resources\TaperTipoResource\Pages;

use App\Filament\Resources\TaperTipoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTaperTipos extends ListRecords
{
    protected static string $resource = TaperTipoResource::class;
    protected function getHeaderActions(): array { return [CreateAction::make()->label('Nuevo tipo de taper')]; }
}
