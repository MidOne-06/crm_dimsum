<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReglaSustitucionProductoResource\Pages;
use App\Models\KardexMovimiento;
use App\Models\ReglaSustitucionProducto;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

/** Producto sustituto válido cuando falta la presentación exacta en origen (Directiva de Transferencia, Fase 0). */
class ReglaSustitucionProductoResource extends Resource
{
    protected static ?string $model = ReglaSustitucionProducto::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';
    protected static ?string $navigationLabel = 'Sustitución de productos';
    protected static ?string $modelLabel = 'Regla de sustitución';
    protected static ?string $pluralModelLabel = 'Sustitución de productos';
    protected static string|\UnitEnum|null $navigationGroup = 'Configuración DT';
    protected static ?int $navigationSort = 26;

    public static function canViewAny(): bool { return (bool) auth()->user()?->hasPermission('tapers.manage'); }
    public static function canCreate(): bool { return (bool) auth()->user()?->hasPermission('tapers.manage'); }
    public static function canEdit($record): bool { return (bool) auth()->user()?->hasPermission('tapers.manage'); }
    public static function canDelete($record): bool { return (bool) auth()->user()?->hasPermission('tapers.manage'); }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make()->schema([
                Select::make('item_original_id')->label('Producto original (el que puede faltar)')
                    ->searchable()->native(false)->required()
                    ->getSearchResultsUsing(fn (string $search) => static::productSearchResults($search))
                    ->getOptionLabelUsing(fn ($value) => static::productLabel((string) $value))
                    ->afterStateUpdated(fn (callable $set, ?string $state) => $set('item_original_nombre', $state ? static::productNombrePuro((string) $state) : null))
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, callable $get) => $rule->where('item_sustituto_id', $get('item_sustituto_id')),
                    )
                    ->live(),
                Select::make('item_sustituto_id')->label('Producto sustituto')
                    ->searchable()->native(false)->required()
                    ->getSearchResultsUsing(fn (string $search) => static::productSearchResults($search))
                    ->getOptionLabelUsing(fn ($value) => static::productLabel((string) $value))
                    ->afterStateUpdated(fn (callable $set, ?string $state) => $set('item_sustituto_nombre', $state ? static::productNombrePuro((string) $state) : null))
                    ->different('item_original_id')
                    ->live(),
                Toggle::make('activo')->label('Regla activa')->default(true)->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('item_original_nombre')->label('Original')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('item_sustituto_nombre')->label('Sustituto')->searchable()->wrap()->icon('heroicon-m-arrow-right'),
                Tables\Columns\IconColumn::make('activo')->label('Activa')->boolean(),
            ])
            ->defaultSort('item_original_nombre')
            ->actions([EditAction::make()->iconButton()->tooltip('Editar'), DeleteAction::make()->iconButton()->tooltip('Eliminar')]);
    }

    protected static function productSearchResults(string $search): array
    {
        $search = trim($search);
        if ($search === '') return [];

        return KardexMovimiento::query()
            ->where(fn ($q) => $q->where('item_nombre', 'ilike', "%{$search}%")->orWhere('cod_interno', 'ilike', "%{$search}%"))
            ->selectRaw('item_id, MAX(cod_interno) AS cod_interno, MAX(item_nombre) AS item_nombre')
            ->groupBy('item_id')->orderBy('item_nombre')->limit(30)->get()
            ->mapWithKeys(fn ($row) => [(string) $row->item_id => trim("{$row->cod_interno} · {$row->item_nombre}", ' ·')])->all();
    }

    protected static function productLabel(string $itemId): ?string
    {
        $row = KardexMovimiento::query()->where('item_id', $itemId)
            ->selectRaw('MAX(cod_interno) AS cod_interno, MAX(item_nombre) AS item_nombre')->first();

        return $row ? trim("{$row->cod_interno} · {$row->item_nombre}", ' ·') : null;
    }

    /** Nombre puro (sin código) para guardar en item_original_nombre/item_sustituto_nombre -- el combinado "código · nombre" es solo para la etiqueta del desplegable. */
    protected static function productNombrePuro(string $itemId): ?string
    {
        return KardexMovimiento::query()->where('item_id', $itemId)
            ->orderByDesc('id')->value('item_nombre');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListReglaSustitucionProductos::route('/')];
    }
}
