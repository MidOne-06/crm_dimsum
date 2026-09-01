<?php

namespace App\Filament\Resources\ProductoTaperResource\Pages;

use App\Filament\Resources\ProductoTaperResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProductoTaper extends EditRecord
{
    protected static string $resource = ProductoTaperResource::class;
    protected function getHeaderActions(): array { return [DeleteAction::make()]; }
}
