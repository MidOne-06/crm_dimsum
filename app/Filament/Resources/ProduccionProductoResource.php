<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProduccionProductoResource\Pages;
use App\Models\ProduccionProducto;
use App\Services\ProduccionCatalogoRestaurantService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class ProduccionProductoResource extends Resource
{
    protected static ?string $model = ProduccionProducto::class;
    protected static ?string $recordTitleAttribute = 'nombre';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-plus';
    protected static ?string $navigationLabel = 'Productos de producción';
    protected static ?string $modelLabel = 'Producto de producción';
    protected static ?string $pluralModelLabel = 'Productos de producción';
    protected static string|\UnitEnum|null $navigationGroup = 'Producción';
    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool { return (bool) auth()->user()?->hasPermission('produccion-productos.view'); }
    public static function canCreate(): bool { return (bool) auth()->user()?->hasPermission('produccion-productos.manage'); }
    public static function canEdit($record): bool { return (bool) auth()->user()?->hasPermission('produccion-productos.manage'); }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make()->columnSpanFull()->schema([
                Grid::make(['default' => 1, 'md' => 4])->schema([
                    static::selectorRestaurant(true)->columnSpanFull(),
                    TextInput::make('codigo')->label('Código')->readOnly()->columnSpan(['md' => 1]),
                    TextInput::make('nombre')->label('Producto')->readOnly()->required()->columnSpan(['md' => 2]),
                    TextInput::make('unidad')->label('Unidad')->readOnly()->required()->default('UNIDAD')->columnSpan(['md' => 1]),
                    Select::make('produccion_categoria_id')->label('Categoría')->relationship('categoria', 'nombre')->searchable()->preload()->columnSpanFull(),
                    Toggle::make('activo')->label('Activo')->default(true)->columnSpanFull(),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('codigo')->label('Código')->searchable(),
            Tables\Columns\TextColumn::make('nombre')->label('Producto')->searchable()->sortable()->weight('medium')->wrap(),
            Tables\Columns\TextColumn::make('unidad')->label('Unidad')->sortable(),
            Tables\Columns\TextColumn::make('categoria.nombre')->label('Categoría')->placeholder('Sin categoría')->sortable(),
            Tables\Columns\IconColumn::make('activo')->label('Activo')->boolean(),
        ])->filters([
            Tables\Filters\SelectFilter::make('produccion_categoria_id')->label('Categoría')->relationship('categoria', 'nombre')->searchable()->preload(),
            Tables\Filters\Filter::make('sin_categoria')->label('Sin categoría')->query(fn ($query) => $query->whereNull('produccion_categoria_id')),
        ])->defaultSort('nombre')->recordTitleAttribute('nombre')->actions([
            Action::make('actualizar_restaurant')->label('Actualizar')->icon('heroicon-o-arrow-path')->modalWidth('5xl')
                ->modalSubmitActionLabel('Actualizar')->modalCancelActionLabel('Cancelar')->schema([
                    Grid::make(['default' => 1])->columnSpanFull()->schema([static::selectorRestaurant()->columnSpanFull()]),
                ])
                ->action(function (ProduccionProducto $record, array $data): void {
                    static::actualizarDesdeRestaurant($record, $data);
                    Notification::make()->success()->title('Producto actualizado')->send();
                }),
            EditAction::make()->iconButton()->tooltip('Editar'),
        ]);
    }

    public static function selectorRestaurant(bool $soloCreacion = false): Select
    {
        $selector = Select::make('restaurant_origen')
            ->label('Producto')
            ->searchable()
            ->optionsLimit(8)
            ->getSearchResultsUsing(fn (string $search): array => app(ProduccionCatalogoRestaurantService::class)->opciones($search))
            ->live()
            ->required()
            ->afterStateUpdated(function (Set $set, ?string $state): void {
                $producto = app(ProduccionCatalogoRestaurantService::class)->productoDesdeClave($state);

                if (! $producto) {
                    return;
                }

                foreach ($producto as $campo => $valor) {
                    $set($campo, $valor);
                }
            });

        return $soloCreacion ? $selector->visibleOn('create') : $selector;
    }

    /** @param array<string, mixed> $data */
    public static function crearDesdeRestaurant(array $data): ProduccionProducto
    {
        $producto = static::datosRestaurant($data);

        return ProduccionProducto::query()->create([
            ...$producto,
            'produccion_categoria_id' => $data['produccion_categoria_id'] ?? null,
            'activo' => (bool) ($data['activo'] ?? true),
        ]);
    }

    /** @param array<string, mixed> $data */
    public static function actualizarDesdeRestaurant(ProduccionProducto $record, array $data): void
    {
        $record->update(static::datosRestaurant($data, $record));
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function datosRestaurant(array $data, ?ProduccionProducto $excepto = null): array
    {
        $producto = app(ProduccionCatalogoRestaurantService::class)->productoDesdeClave($data['restaurant_origen'] ?? null);

        if (! $producto) {
            throw ValidationException::withMessages(['restaurant_origen' => 'Selecciona un producto válido de Restaurant.']);
        }

        $duplicado = ProduccionProducto::query()
            ->where('restaurant_item_id', $producto['restaurant_item_id'])
            ->where('restaurant_item_tipo', $producto['restaurant_item_tipo'])
            ->where(fn ($query) => $producto['restaurant_presentacion_id'] === null ? $query->whereNull('restaurant_presentacion_id') : $query->where('restaurant_presentacion_id', $producto['restaurant_presentacion_id']))
            ->when($excepto, fn ($query) => $query->whereKeyNot($excepto->getKey()))
            ->exists();

        if ($duplicado) {
            throw ValidationException::withMessages(['restaurant_origen' => 'El producto ya está registrado.']);
        }

        return $producto;
    }

    public static function getPages(): array { return ['index' => Pages\ListProduccionProductos::route('/')]; }
}
