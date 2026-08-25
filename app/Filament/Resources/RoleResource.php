<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoleResource\Pages;
use App\Models\Role;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';
    protected static ?string $navigationLabel = 'Roles';
    protected static ?string $modelLabel = 'Rol';
    protected static ?string $pluralModelLabel = 'Roles';
    protected static string|\UnitEnum|null $navigationGroup = 'Seguridad';
    protected static ?int $navigationSort = 91;

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make()
                ->schema([
                    TextInput::make('name')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(100)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (?string $state, callable $set) => $set('slug', Str::slug((string) $state))),
                    TextInput::make('slug')->label('Identificador')->required()->alphaDash()->unique(ignoreRecord: true)->maxLength(100),
                ])
                ->columns(['default' => 1, 'md' => 2]),
            Section::make('Permisos')
                ->schema([
                    Select::make('permissions')
                        ->label('Permisos asignados')
                        ->relationship('permissions', 'name')
                        ->multiple()
                        ->searchable()
                        ->preload(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Rol')->searchable()->sortable()->weight('medium')->limit(42)->tooltip(fn (Role $record): string => $record->name),
                Tables\Columns\TextColumn::make('slug')->label('Identificador')->toggleable()->tooltip(fn (Role $record): string => $record->slug),
                Tables\Columns\TextColumn::make('permissions_count')->label('Permisos')->counts('permissions')->alignCenter()->sortable(),
                Tables\Columns\TextColumn::make('users_count')->label('Usuarios')->counts('users')->alignCenter()->sortable(),
                Tables\Columns\IconColumn::make('is_system')->label('Base')->boolean()->alignCenter(),
            ])
            ->defaultSort('name')
            ->recordTitleAttribute('name')
            ->actions([
                EditAction::make()->iconButton()->tooltip('Editar rol'),
                DeleteAction::make()->iconButton()->tooltip('Eliminar rol'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}
