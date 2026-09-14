<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProduccionProductoResource\Pages;
use App\Models\ProduccionProducto;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class ProduccionProductoResource extends Resource
{
    protected static ?string $model = ProduccionProducto::class;
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
                    TextInput::make('codigo')->label('Código')->maxLength(80)->unique(ignoreRecord: true)->columnSpan(['md' => 1]),
                    TextInput::make('nombre')->label('Producto')->required()->maxLength(255)->columnSpan(['md' => 2]),
                    TextInput::make('unidad')->label('Unidad')->required()->default('UNIDAD')->maxLength(50)->columnSpan(['md' => 1]),
                    Toggle::make('activo')->label('Activo')->default(true)->columnSpan(['md' => 1]),
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
            Tables\Columns\IconColumn::make('activo')->label('Activo')->boolean(),
        ])->defaultSort('nombre')->recordTitleAttribute('nombre')->actions([
            EditAction::make()->iconButton()->tooltip('Editar'),
        ]);
    }

    public static function getPages(): array { return ['index' => Pages\ListProduccionProductos::route('/')]; }
}
