<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TaperTipoResource\Pages;
use App\Models\TaperTipo;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Tipos de taper (Directiva de Transferencia, Fase 0): los contenedores en
 * los que se despachan los productos de transferencia central -- "Taper
 * chico", "Taper grande", etc. Su capacidad real por producto se define en
 * ProductoTaperResource, y el máximo que soporta cada local en
 * LocalTaperCapacidadResource.
 */
class TaperTipoResource extends Resource
{
    protected static ?string $model = TaperTipo::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';
    protected static ?string $navigationLabel = 'Tipos de taper';
    protected static ?string $modelLabel = 'Tipo de taper';
    protected static ?string $pluralModelLabel = 'Tipos de taper';
    protected static string|\UnitEnum|null $navigationGroup = 'Requerimientos de Stock';
    protected static ?int $navigationSort = 20;

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
            Section::make()
                ->schema([
                    TextInput::make('nombre')->label('Nombre')->required()->maxLength(80)->placeholder('Ej. Taper chico'),
                    Textarea::make('descripcion')->label('Descripción')->rows(2)->maxLength(255),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('nombre')->label('Nombre')->searchable()->sortable()->weight('medium'),
                Tables\Columns\TextColumn::make('descripcion')->label('Descripción')->limit(60)->toggleable(),
                Tables\Columns\TextColumn::make('productos_count')->label('Productos')->counts('productos')->alignCenter()->sortable(),
                Tables\Columns\TextColumn::make('local_capacidades_count')->label('Locales con tope')->counts('localCapacidades')->alignCenter()->sortable(),
            ])
            ->defaultSort('nombre')
            ->recordTitleAttribute('nombre')
            ->actions([
                EditAction::make()->iconButton()->tooltip('Editar tipo de taper'),
                DeleteAction::make()->iconButton()->tooltip('Eliminar tipo de taper'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTaperTipos::route('/'),
            'create' => Pages\CreateTaperTipo::route('/create'),
            'edit' => Pages\EditTaperTipo::route('/{record}/edit'),
        ];
    }
}
