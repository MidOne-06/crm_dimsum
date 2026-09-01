<?php

namespace App\Filament\Resources\LocalTaperCapacidadResource\Pages;

use App\Filament\Resources\LocalTaperCapacidadResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLocalTaperCapacidad extends EditRecord
{
    protected static string $resource = LocalTaperCapacidadResource::class;
    protected function getHeaderActions(): array { return [DeleteAction::make()]; }
}
