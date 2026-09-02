<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FabricaCapacidadProductoResource\Pages;
use App\Models\FabricaCapacidadProducto;
use App\Models\KardexMovimiento;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Techo de producción diaria por producto en FABRICA (Directiva de
 * Transferencia, Fase 0). El histórico de lo REALMENTE producido se lee
 * directo de kardex_movimientos, no se duplica -- ver columna "Producido
 * hoy" en la tabla.
 */
class FabricaCapacidadProductoResource extends Resource
{
    protected static ?string $model = FabricaCapacidadProducto::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';
    protected static ?string $navigationLabel = 'Capacidad de FABRICA';
    protected static ?string $modelLabel = 'Capacidad de FABRICA';
    protected static ?string $pluralModelLabel = 'Capacidad de FABRICA';
    protected static string|\UnitEnum|null $navigationGroup = 'Configuración DT';
    protected static ?int $navigationSort = 25;

    public static function canViewAny(): bool { return (bool) auth()->user()?->hasPermission('tapers.manage'); }
    public static function canCreate(): bool { return (bool) auth()->user()?->hasPermission('tapers.manage'); }
    public static function canEdit($record): bool { return (bool) auth()->user()?->hasPermission('tapers.manage'); }
    public static function canDelete($record): bool { return (bool) auth()->user()?->hasPermission('tapers.manage'); }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make()->schema([
                Select::make('item_id')
                    ->label('Producto')
                    ->searchable()->native(false)->required()
                    ->unique(ignoreRecord: true)
                    ->getSearchResultsUsing(fn (string $search): array => static::productSearchResults($search))
                    ->getOptionLabelUsing(fn ($value): ?string => static::productLabel((string) $value))
                    ->afterStateUpdated(function (callable $set, ?string $state): void {
                        if (! $state) return;
                        $row = KardexMovimiento::query()->where('item_id', $state)->latest('id')->first();
                        $set('item_codigo', $row?->cod_interno);
                        $set('item_nombre', $row?->item_nombre);
                    })->live(),
                TextInput::make('item_codigo')->label('Código')->readOnly(),
                TextInput::make('item_nombre')->label('Nombre')->readOnly()->required(),
                TextInput::make('capacidad_maxima_dia')->label('Capacidad máxima por día')->numeric()->minValue(0)->required(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('item_codigo')->label('Código')->searchable(),
                Tables\Columns\TextColumn::make('item_nombre')->label('Producto')->searchable()->weight('medium')->wrap(),
                Tables\Columns\TextColumn::make('capacidad_maxima_dia')->label('Techo declarado/día')->numeric()->alignEnd()->sortable(),
                Tables\Columns\TextColumn::make('producido_hoy')->label('Producido hoy')->state(fn (FabricaCapacidadProducto $r) => number_format($r->producidoEn(now())))->alignEnd()
                    ->color(fn (FabricaCapacidadProducto $r) => $r->producidoEn(now()) > $r->capacidad_maxima_dia ? 'danger' : 'gray'),
            ])
            ->defaultSort('item_nombre')
            ->recordTitleAttribute('item_nombre')
            ->actions([EditAction::make()->iconButton()->tooltip('Editar'), DeleteAction::make()->iconButton()->tooltip('Eliminar')]);
    }

    /** @return array<string, string> */
    protected static function productSearchResults(string $search): array
    {
        $search = trim($search);
        if ($search === '') return [];

        return KardexMovimiento::query()
            ->where(fn ($q) => $q->where('item_nombre', 'ilike', "%{$search}%")->orWhere('cod_interno', 'ilike', "%{$search}%"))
            ->selectRaw('item_id, MAX(cod_interno) AS cod_interno, MAX(item_nombre) AS item_nombre')
            ->groupBy('item_id')->orderBy('item_nombre')->limit(30)->get()
            ->mapWithKeys(fn ($row): array => [(string) $row->item_id => trim("{$row->cod_interno} · {$row->item_nombre}", ' ·')])->all();
    }

    protected static function productLabel(string $itemId): ?string
    {
        $row = KardexMovimiento::query()->where('item_id', $itemId)
            ->selectRaw('MAX(cod_interno) AS cod_interno, MAX(item_nombre) AS item_nombre')->first();

        return $row ? trim("{$row->cod_interno} · {$row->item_nombre}", ' ·') : null;
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListFabricaCapacidadProductos::route('/')];
    }
}
