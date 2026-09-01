<?php

namespace App\Filament\Resources\TaperTipoResource\Pages;

use App\Filament\Resources\TaperTipoResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTaperTipo extends EditRecord
{
    protected static string $resource = TaperTipoResource::class;
    protected function getHeaderActions(): array { return [DeleteAction::make()]; }
}
