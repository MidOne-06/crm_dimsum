<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductoTaperResource\Pages;
use App\Models\KardexMovimiento;
use App\Models\ProductoTaper;
use App\Models\TaperTipo;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

/**
 * Capacidad en unidades de un producto según el tipo de taper en el que se
 * despacha (Directiva de Transferencia, Fase 0). Un mismo producto puede
 * tener varias filas -- una por cada presentación de taper que use (ej. Siu
 * Mai en taper chico = 50 un., en taper grande = 120 un.).
 */
class ProductoTaperResource extends Resource
{
    protected static ?string $model = ProductoTaper::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cube';
    protected static ?string $navigationLabel = 'Capacidad por producto';
    protected static ?string $modelLabel = 'Capacidad de taper';
    protected static ?string $pluralModelLabel = 'Capacidades por producto';
    protected static string|\UnitEnum|null $navigationGroup = 'Configuración DT';
    protected static ?int $navigationSort = 21;

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->hasPermission('tapers.manage');
    }

    public static function canCreate(): bool
    {
        return (bool) auth()->user()?->hasPermission('tapers.manage');
    }

    public static function canEdit($record): bool
    {
        return (bool) auth()->user()?->hasPermission('tapers.manage');
    }

    public static function canDelete($record): bool
    {
        return (bool) auth()->user()?->hasPermission('tapers.manage');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Grid::make(['default' => 1, 'md' => 2, 'xl' => 4])
                ->columnSpanFull()
                ->schema([
                    Select::make('item_id')
                        ->label('Producto')
                        ->searchable()
                        ->native(false)
                        ->required()
                        ->getSearchResultsUsing(fn (string $search): array => static::productSearchResults($search))
                        ->getOptionLabelUsing(fn ($value): ?string => static::productLabel((string) $value))
                        ->afterStateUpdated(function (callable $set, ?string $state): void {
                            if (! $state) {
                                return;
                            }
                            $row = KardexMovimiento::query()->where('item_id', $state)->latest('id')->first();
                            $set('item_codigo', $row?->cod_interno);
                            $set('item_nombre', $row?->item_nombre);
                        })
                        ->live()
                        ->columnSpan(['xl' => 2]),
                    Select::make('taper_tipo_id')
                        ->label('Tipo de taper')
                        ->relationship('taperTipo', 'nombre')
                        ->native(false)
                        ->searchable()
                        ->preload()
                        ->required()
                        ->unique(
                            table: 'producto_tapers',
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $rule, callable $get) => $rule->where('item_id', $get('item_id')),
                        )
                        ->columnSpan(1),
                    TextInput::make('capacidad_unidades')
                        ->label('Unidades por taper')
                        ->numeric()
                        ->minValue(1)
                        ->required()
                        ->columnSpan(1),
                    TextInput::make('item_codigo')->readOnly()->hidden(),
                    TextInput::make('item_nombre')->readOnly()->required()->hidden(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('item_codigo')->label('Código')->searchable(),
                Tables\Columns\TextColumn::make('item_nombre')->label('Producto')->searchable()->weight('medium')->wrap(),
                Tables\Columns\TextColumn::make('taperTipo.nombre')->label('Tipo de taper')->badge()->sortable(),
                Tables\Columns\TextColumn::make('capacidad_unidades')->label('Unidades por taper')->numeric()->alignEnd()->sortable(),
            ])
            ->defaultSort('item_nombre')
            ->recordTitleAttribute('item_nombre')
            ->actions([
                EditAction::make()->iconButton()->tooltip('Editar capacidad')->modalWidth('5xl')->stickyModalHeader()->stickyModalFooter(),
                DeleteAction::make()->iconButton()->tooltip('Eliminar'),
            ]);
    }

    /** @return array<string, string> */
    protected static function productSearchResults(string $search): array
    {
        $search = trim($search);
        if ($search === '') {
            return [];
        }

        return KardexMovimiento::query()
            ->where(function ($query) use ($search): void {
                $query->where('item_nombre', 'ilike', "%{$search}%")
                    ->orWhere('cod_interno', 'ilike', "%{$search}%");
            })
            ->selectRaw('item_id, MAX(cod_interno) AS cod_interno, MAX(item_nombre) AS item_nombre')
            ->groupBy('item_id')
            ->orderBy('item_nombre')
            ->limit(30)
            ->get()
            ->mapWithKeys(fn ($row): array => [(string) $row->item_id => trim("{$row->cod_interno} · {$row->item_nombre}", ' ·')])
            ->all();
    }

    protected static function productLabel(string $itemId): ?string
    {
        $row = KardexMovimiento::query()
            ->where('item_id', $itemId)
            ->selectRaw('MAX(cod_interno) AS cod_interno, MAX(item_nombre) AS item_nombre')
            ->first();

        return $row ? trim("{$row->cod_interno} · {$row->item_nombre}", ' ·') : null;
    }

    /** Sin rutas 'create'/'edit': se manejan en modal sobre la lista. */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductoTapers::route('/'),
        ];
    }
}
