<?php

namespace App\Filament\Pages\Ventas;

use App\Models\ProductoComercialRestaurant;
use App\Services\CosteoComercialService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CostosRecetasComerciales extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationLabel = 'Costos y recetas';

    protected static ?string $title = 'Costos y recetas comerciales';

    protected static string|\UnitEnum|null $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'ventas/costos-recetas';

    protected string $view = 'filament.pages.ventas.costos-recetas';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('ventas.costos.view');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => ProductoComercialRestaurant::query()
                ->withCount(['composiciones', 'recetasManuales'])
                ->orderBy('descripcion_venta')
                ->orderBy('nombre'))
            ->columns([
                TextColumn::make('codigo')->label('Código')->searchable()->toggleable(),
                TextColumn::make('descripcion_venta')->label('Producto Restaurant')->searchable()->wrap()
                    ->description(fn (ProductoComercialRestaurant $record): string => $record->nombre ?: 'Sin nombre Restaurant'),
                TextColumn::make('unidad')->label('Unidad')->toggleable(),
                IconColumn::make('es_combo')->label('Combo')->boolean()->alignCenter(),
                TextColumn::make('composiciones_count')->label('Recetas Restaurant')->alignCenter()->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'info' : 'gray'),
                TextColumn::make('recetas_manuales_count')->label('Recetas manuales')->alignCenter()->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray'),
                TextColumn::make('costo_vigente')->label('Costo base vigente')->alignEnd()
                    ->state(fn (ProductoComercialRestaurant $record): ?float => $this->costeo()->costoVigente($record->restaurant_producto_id)?->costo_unitario)
                    ->money('PEN'),
            ])
            ->filters([
                SelectFilter::make('tipo')->label('Tipo')->options([
                    'combo' => 'Con composición Restaurant',
                    'simple' => 'Sin composición Restaurant',
                ])->query(function (Builder $query, array $data): Builder {
                    return match ($data['value'] ?? null) {
                        'combo' => $query->where('tiene_composicion_restaurant', true),
                        'simple' => $query->where('tiene_composicion_restaurant', false),
                        default => $query,
                    };
                }),
            ])
            ->recordActions([
                Action::make('costo')
                    ->label('Costo')
                    ->icon('heroicon-o-currency-dollar')
                    ->visible(fn (): bool => (bool) auth()->user()?->hasPermission('ventas.costos.edit'))
                    ->modalHeading(fn (ProductoComercialRestaurant $record): string => 'Costo base · '.$this->etiquetaProducto($record))
                    ->modalWidth('5xl')
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->modalSubmitActionLabel('Guardar costo')
                    ->modalCancelActionLabel('Cancelar')
                    ->fillForm(function (ProductoComercialRestaurant $record): array {
                        $costo = $this->costeo()->costoVigente($record->restaurant_producto_id);

                        return [
                            'vigente_desde' => $costo?->vigente_desde?->toDateString() ?? now()->toDateString(),
                            'costo_unitario' => $costo?->costo_unitario,
                            'observacion' => $costo?->observacion,
                        ];
                    })
                    ->schema(fn (ProductoComercialRestaurant $record): array => $this->costoSchema($record))
                    ->action(function (ProductoComercialRestaurant $record, array $data): void {
                        $this->costeo()->guardarCosto($record, $data, auth()->user());
                        $this->resetTable();
                        Notification::make()->success()->title('Costo guardado')->send();
                    }),
                Action::make('receta')
                    ->label('Receta')
                    ->icon('heroicon-o-squares-plus')
                    ->visible(fn (): bool => (bool) auth()->user()?->hasPermission('ventas.costos.edit'))
                    ->modalHeading(fn (ProductoComercialRestaurant $record): string => 'Receta manual · '.$this->etiquetaProducto($record))
                    ->modalWidth('5xl')
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->modalSubmitActionLabel('Guardar receta')
                    ->modalCancelActionLabel('Cancelar')
                    ->fillForm(function (ProductoComercialRestaurant $record): array {
                        $receta = $this->costeo()->recetaManualVigente($record->restaurant_producto_id);

                        return [
                            'vigente_desde' => $receta?->vigente_desde?->toDateString() ?? now()->toDateString(),
                            'observacion' => $receta?->observacion,
                            'componentes' => $receta?->componentes->map(fn ($componente): array => [
                                'producto_restaurant_id' => $componente->componente_restaurant_producto_id,
                                'cantidad_por_producto' => $componente->cantidad_por_producto,
                            ])->all() ?? [],
                        ];
                    })
                    ->schema(fn (ProductoComercialRestaurant $record): array => $this->recetaSchema($record))
                    ->action(function (ProductoComercialRestaurant $record, array $data): void {
                        $this->costeo()->guardarRecetaManual($record, $data, auth()->user());
                        $this->resetTable();
                        Notification::make()->success()->title('Receta guardada')->send();
                    }),
            ])
            ->defaultSort('descripcion_venta')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('Aún no hay productos comerciales extraídos de Restaurant.');
    }

    /** @return array<int, Component> */
    private function costoSchema(ProductoComercialRestaurant $producto): array
    {
        return [
            Grid::make(['default' => 1, 'md' => 4])->schema([
                TextInput::make('producto')->label('Producto Restaurant')->default($this->etiquetaProducto($producto))->disabled()->dehydrated(false)->columnSpan(['md' => 2]),
                DatePicker::make('vigente_desde')->label('Vigente desde')->native(false)->required(),
                TextInput::make('costo_unitario')->label('Costo unitario')->numeric()->prefix('S/')->minValue(0)->required(),
                Textarea::make('observacion')->label('Observación')->rows(2)->maxLength(1000)->columnSpanFull(),
            ]),
        ];
    }

    /** @return array<int, Component> */
    private function recetaSchema(ProductoComercialRestaurant $producto): array
    {
        return [
            Grid::make(['default' => 1, 'md' => 4])->schema([
                TextInput::make('producto')->label('Producto Restaurant')->default($this->etiquetaProducto($producto))->disabled()->dehydrated(false)->columnSpan(['md' => 2]),
                DatePicker::make('vigente_desde')->label('Vigente desde')->native(false)->required(),
                Textarea::make('observacion')->label('Observación')->rows(2)->maxLength(1000)->columnSpanFull(),
                Repeater::make('componentes')->label('Componentes')->addActionLabel('Agregar componente')->defaultItems(0)
                    ->reorderable(false)->itemNumbers(false)->columns(['default' => 1, 'md' => 4])->columnSpanFull()
                    ->schema([
                        Select::make('producto_restaurant_id')->label('Componente')->options(fn (): array => $this->opcionesComponentes($producto->restaurant_producto_id))->native(false)->searchable()->required()->columnSpan(['md' => 3]),
                        TextInput::make('cantidad_por_producto')->label('Cantidad')->numeric()->minValue(0.000001)->required(),
                    ]),
            ]),
        ];
    }

    /** @return array<string, string> */
    private function opcionesComponentes(string $excepto): array
    {
        return ProductoComercialRestaurant::query()->where('restaurant_producto_id', '!=', $excepto)
            ->orderBy('descripcion_venta')->get()
            ->mapWithKeys(fn (ProductoComercialRestaurant $producto): array => [
                $producto->restaurant_producto_id => $this->etiquetaProducto($producto),
            ])->all();
    }

    private function etiquetaProducto(ProductoComercialRestaurant $producto): string
    {
        $descripcion = $producto->descripcion_venta ?: $producto->nombre ?: 'Producto sin descripción';
        $codigo = filled($producto->codigo) ? $producto->codigo.' · ' : '';

        return $codigo.$descripcion.($producto->unidad ? ' · '.$producto->unidad : '');
    }

    private function costeo(): CosteoComercialService
    {
        return app(CosteoComercialService::class);
    }
}
