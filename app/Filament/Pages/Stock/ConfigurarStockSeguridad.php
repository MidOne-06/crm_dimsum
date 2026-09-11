<?php

namespace App\Filament\Pages\Stock;

use App\Models\DirectivaTransferenciaSetting;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Nivel de servicio del stock de seguridad -- pedido explícito del usuario
 * (2026-09-11, barrida de huecos funcionales): antes de esto, el 95%
 * elegido para la fórmula (`stock_seguridad = factor × desviación
 * estándar`, ver `DirectivaTransferenciaService`) vivía hardcodeado en
 * código -- cambiarlo exigía un deploy. Mismo patrón que "Apariencia"
 * (`ConfigurarIdentidadVisual`): fila única en `directiva_transferencia_settings`,
 * un formulario simple que la edita.
 */
class ConfigurarStockSeguridad extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';
    protected static ?string $navigationLabel = 'Stock de seguridad';
    protected static ?string $title = 'Stock de seguridad';
    protected static string|\UnitEnum|null $navigationGroup = 'Stock Inicial';
    protected static ?int $navigationSort = 46;
    protected static ?string $slug = 'stock-inicial/stock-seguridad';
    protected string $view = 'filament.pages.stock.configurar-stock-seguridad';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('directiva-transferencia.configurar');
    }

    public function mount(): void
    {
        $this->form->fill(DirectivaTransferenciaSetting::current()->only(['nivel_servicio_pct']));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Nivel de servicio')
                    ->description('Qué tan grande es el colchón que la Directiva de Transferencia suma sobre la demanda promedio para absorber semanas de venta más alta de lo normal, antes de restar el stock proyectado.')
                    ->compact()
                    ->schema([
                        Radio::make('nivel_servicio_pct')
                            ->hiddenLabel()
                            ->options([
                                90 => '90% -- colchón más chico, algo más de riesgo de quiebre en semanas altas',
                                95 => '95% -- recomendado',
                                98 => '98% -- colchón más grande, casi nunca quiebra por variabilidad',
                            ])
                            ->descriptions([
                                90 => 'factor 1.28',
                                95 => 'factor 1.65',
                                98 => 'factor 2.05',
                            ])
                            ->required(),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $nivel = (int) $this->form->getState()['nivel_servicio_pct'];
        $factor = DirectivaTransferenciaSetting::NIVELES_DISPONIBLES[$nivel];

        $setting = DirectivaTransferenciaSetting::current();
        $setting->fill([
            'nivel_servicio_pct' => $nivel,
            'factor_servicio' => $factor,
            'actualizado_por' => auth()->id(),
        ]);
        $setting->save();

        Notification::make()
            ->title('Nivel de servicio actualizado')
            ->body("Los próximos cálculos de la Directiva usarán {$nivel}% (factor {$factor}). Los ya calculados no cambian hasta el próximo cálculo.")
            ->success()
            ->send();
    }
}
