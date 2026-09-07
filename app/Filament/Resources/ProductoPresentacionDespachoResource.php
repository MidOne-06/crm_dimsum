<?php

namespace App\Filament\Resources;

use App\Models\ProductoPresentacionDespacho;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Múltiplo de despacho por producto -- tabla dinámica a propósito (pedido
 * explícito del usuario: "esto debe ser implementado en un módulo por
 * producto dinámico ya que en el futuro puede variar"). Base de datos real
 * cargada desde presentacion_despacho.xlsx (07/09/2026); editable desde acá
 * de ahí en adelante, sin tocar código para cambiar un múltiplo.
 */
class ProductoPresentacionDespachoResource extends Resource
{
    protected static ?string $model = ProductoPresentacionDespacho::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cube';
    protected static ?string $navigationLabel = 'Presentación de despacho';
    protected static ?string $modelLabel = 'Presentación de despacho';
    protected static ?string $pluralModelLabel = 'Presentaciones de despacho';
    protected static string|\UnitEnum|null $navigationGroup = 'Stock Inicial';
    protected static ?int $navigationSort = 4;

    public static function canViewAny(): bool
    {
        return static::canManage();
    }

    public static function canCreate(): bool
    {
        return static::canManage();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canManage();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canManage();
    }

    private static function canManage(): bool
    {
        return (bool) auth()->user()?->hasPermission('presentacion-despacho.manage');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make()->schema([
                Select::make('item_key')
                    ->label('Producto')
                    ->options(fn (): array => DB::table('kardex_movimientos')
                        ->whereNotNull('cod_interno')->where('cod_interno', '!=', '')
                        ->selectRaw("DISTINCT item_id, tipo_item, cod_interno, item_nombre")
                        ->get()
                        ->mapWithKeys(fn ($row) => ["{$row->item_id}|{$row->tipo_item}" => "{$row->cod_interno} · {$row->item_nombre}"])
                        ->all())
                    ->native(false)->searchable()->required()->columnSpanFull()
                    ->disabled(fn (?ProductoPresentacionDespacho $record): bool => (bool) $record)
                    ->dehydrated(false)
                    ->afterStateHydrated(function (?ProductoPresentacionDespacho $record, $set): void {
                        if ($record) {
                            $set('item_key', "{$record->item_id}|{$record->item_tipo}");
                        }
                    })
                    ->live()
                    ->afterStateUpdated(function (?string $state, $set): void {
                        if (! $state) {
                            return;
                        }
                        [$itemId, $itemTipo] = array_pad(explode('|', $state, 2), 2, null);
                        $row = DB::table('kardex_movimientos')->where('item_id', $itemId)->where('tipo_item', $itemTipo)
                            ->whereNotNull('cod_interno')->selectRaw('MAX(cod_interno) AS cod_interno, MAX(item_nombre) AS item_nombre')->first();
                        $set('item_id', $itemId);
                        $set('item_tipo', $itemTipo);
                        $set('item_codigo', $row->cod_interno ?? null);
                        $set('item_nombre', $row->item_nombre ?? null);
                    }),
                \Filament\Forms\Components\Hidden::make('item_id')->required(),
                \Filament\Forms\Components\Hidden::make('item_tipo'),
                \Filament\Forms\Components\Hidden::make('item_codigo'),
                \Filament\Forms\Components\Hidden::make('item_nombre')->required(),
                Grid::make(2)->schema([
                    TextInput::make('multiplo')->label('Múltiplo de despacho')->numeric()->required()->minValue(1)->default(1)
                        ->helperText('Ej. 25 -- solo se despacha en cantidades 25, 50, 75...'),
                ]),
                Textarea::make('nota')->label('Nota (opcional)')->rows(2)->columnSpanFull()
                    ->helperText('Ej. "Cantidades de despacho: 15 - 30 - 45 y así" para casos con un patrón distinto al múltiplo simple.'),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('item_codigo')->label('SKU')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('item_nombre')->label('Producto')->searchable()->sortable()->wrap(),
                Tables\Columns\TextColumn::make('multiplo')->label('Múltiplo')->numeric()->alignEnd()->sortable()->badge()->color('info'),
                Tables\Columns\TextColumn::make('nota')->label('Nota')->wrap()->limit(60)->placeholder('--'),
            ])
            ->defaultSort('item_nombre')
            ->recordTitleAttribute('item_nombre')
            ->actions([
                EditAction::make()->iconButton()->tooltip('Editar')->modalWidth('xl')->stickyModalHeader()->stickyModalFooter()->modalSubmitActionLabel('Guardar')->modalCancelActionLabel('Cancelar'),
                DeleteAction::make()->iconButton()->tooltip('Eliminar'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => \App\Filament\Resources\ProductoPresentacionDespachoResource\Pages\ListProductoPresentacionDespachos::route('/'),
        ];
    }
}
