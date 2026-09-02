<?php

namespace App\Filament\Pages\RequerimientosStock;

use App\Models\ConfiguracionProrrateo as ConfiguracionProrrateoModel;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Estrategia activa de prorrateo cuando FABRICA no alcanza para cubrir todo
 * lo pedido (Directiva de Transferencia, Fase 0). Fila única -- si la
 * estrategia es "manual", el orden real se define en Prioridad manual de
 * reparto (PrioridadLocalProrrateoResource).
 */
class ConfiguracionProrrateo extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-scale';
    protected static ?string $navigationLabel = 'Prorrateo ante escasez';
    protected static ?string $title = 'Configuración de prorrateo';
    protected static string|\UnitEnum|null $navigationGroup = 'Configuración DT';
    protected static ?int $navigationSort = 29;
    protected static ?string $slug = 'requerimientos-stock/prorrateo';
    protected string $view = 'filament.pages.requerimientos-stock.configuracion-prorrateo';

    public array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('tapers.manage');
    }

    public function mount(): void
    {
        $this->data = ['estrategia' => ConfiguracionProrrateoModel::singleton()->estrategia];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Cuando FABRICA no alcanza para cubrir todo lo pedido')
                ->description('Se aplica antes de mostrar la sugerencia final de la Fase 1, cuando la suma de lo sugerido supera la capacidad de producción declarada de algún producto.')
                ->schema([
                    Select::make('estrategia')
                        ->label('Estrategia de prorrateo')
                        ->options(ConfiguracionProrrateoModel::etiquetas())
                        ->native(false)->required(),
                ]),
        ])->statePath('data');
    }

    public function guardar(): void
    {
        ConfiguracionProrrateoModel::singleton()->update(['estrategia' => $this->data['estrategia']]);
        Notification::make()->title('Estrategia de prorrateo actualizada')->success()->send();
    }
}
