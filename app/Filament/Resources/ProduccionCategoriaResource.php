<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProduccionCategoriaResource\Pages;
use App\Models\ProduccionCategoria;
use App\Models\ProduccionProducto;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class ProduccionCategoriaResource extends Resource
{
    protected static ?string $model = ProduccionCategoria::class;
    protected static ?string $recordTitleAttribute = 'nombre';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';
    protected static ?string $navigationLabel = 'Categorías de producción';
    protected static ?string $modelLabel = 'Categoría de producción';
    protected static ?string $pluralModelLabel = 'Categorías de producción';
    protected static string|\UnitEnum|null $navigationGroup = 'Producción';
    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool { return (bool) auth()->user()?->hasPermission('produccion-productos.view'); }
    public static function canCreate(): bool { return (bool) auth()->user()?->hasPermission('produccion-productos.manage'); }
    public static function canEdit($record): bool { return (bool) auth()->user()?->hasPermission('produccion-productos.manage'); }
    public static function canDelete($record): bool { return (bool) auth()->user()?->hasPermission('produccion-productos.manage'); }

    public static function getNavigationBadge(): ?string
    {
        $sinCategoria = ProduccionProducto::query()->whereNull('produccion_categoria_id')->count();

        return $sinCategoria > 0 ? (string) $sinCategoria : null;
    }

    public static function getNavigationBadgeColor(): ?string { return 'warning'; }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make()->columnSpanFull()->schema([
                Grid::make(['default' => 1, 'md' => 4])->columnSpanFull()->schema([
                    TextInput::make('nombre')->label('Categoría')->required()->maxLength(120)->unique(ignoreRecord: true)->columnSpan(['md' => 3]),
                    TextInput::make('orden')->label('Orden')->numeric()->integer()->minValue(0)->required()->default(0),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('orden')->label('Orden')->sortable(),
            Tables\Columns\TextColumn::make('nombre')->label('Categoría')->searchable()->sortable()->weight('medium'),
            Tables\Columns\TextColumn::make('productos_count')->label('Productos')->counts('productos')->alignEnd(),
        ])->defaultSort('orden')->recordTitleAttribute('nombre')->actions([
            EditAction::make()->iconButton()->tooltip('Editar'),
            DeleteAction::make()->iconButton()->tooltip('Eliminar'),
        ]);
    }

    public static function getPages(): array { return ['index' => Pages\ListProduccionCategorias::route('/')]; }
}
