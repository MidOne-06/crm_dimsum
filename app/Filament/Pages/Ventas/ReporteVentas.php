<?php

namespace App\Filament\Pages\Ventas;

use App\Filament\Concerns\InteractsWithSales;
use Filament\Pages\Page;

class ReporteVentas extends Page
{
    use InteractsWithSales;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationLabel = 'Reporte de ventas';

    protected static ?string $title = 'Reporte de ventas';

    protected static string|\UnitEnum|null $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'ventas/reporte';

    protected string $view = 'filament.pages.ventas.reporte';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('ventas.reporte.view');
    }

    protected function detailPermissionSlug(): string
    {
        return 'ventas.reporte.ver-detalle';
    }
}
