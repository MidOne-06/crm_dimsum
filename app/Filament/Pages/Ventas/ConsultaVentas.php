<?php

namespace App\Filament\Pages\Ventas;

use App\Filament\Concerns\InteractsWithSales;
use Filament\Pages\Page;

class ConsultaVentas extends Page
{
    use InteractsWithSales;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static ?string $navigationLabel = 'Consulta de ventas';

    protected static ?string $title = 'Consulta de ventas';

    protected static string|\UnitEnum|null $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 21;

    protected static ?string $slug = 'ventas/consulta';

    protected string $view = 'filament.pages.ventas.consulta';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('ventas.consulta.view');
    }

    protected function detailPermissionSlug(): string
    {
        return 'ventas.consulta.ver-detalle';
    }
}
