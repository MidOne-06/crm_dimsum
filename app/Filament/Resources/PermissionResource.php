<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PermissionResource\Pages;
use App\Models\Permission;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PermissionResource extends Resource
{
    protected static ?string $model = Permission::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-key';
    protected static ?string $navigationLabel = 'Permisos';
    protected static ?string $modelLabel = 'Permiso';
    protected static ?string $pluralModelLabel = 'Permisos';
    protected static string|\UnitEnum|null $navigationGroup = 'Seguridad';
    protected static ?int $navigationSort = 92;

    public static function canViewAny(): bool
    {
        return static::canManagePermissions();
    }

    public static function canCreate(): bool
    {
        return static::canManagePermissions();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canManagePermissions() && static::canManagePermissionRecord($record);
    }

    public static function canDelete(Model $record): bool
    {
        return static::canManagePermissions() && static::canManagePermissionRecord($record);
    }

    private static function canManagePermissions(): bool
    {
        return (bool) auth()->user()?->hasPermission('permissions.manage');
    }

    /** No se permite alterar permisos de sistema salvo a un superadministrador. */
    private static function canManagePermissionRecord(Model $record): bool
    {
        if (! $record instanceof Permission || ! $record->is_system) {
            return true;
        }

        $actor = auth()->user();

        return (bool) ($actor?->isPanelAdministrator()
            || $actor?->roles()->where('slug', 'superadministrador')->exists());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make()
                ->schema([
                    TextInput::make('name')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(120)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (?string $state, callable $set) => $set('slug', Str::slug((string) $state, '.'))),
                    TextInput::make('slug')->label('Identificador')->required()->regex('/^[a-z0-9]+(?:[.-][a-z0-9]+)*$/')->unique(ignoreRecord: true)->maxLength(120),
                    Select::make('module')->label('Módulo')->options([
                        'Seguridad' => 'Seguridad',
                        'Apariencia' => 'Apariencia',
                        'Stock Actual' => 'Stock Actual',
                    ])->native(false),
                ])
                ->columns(['default' => 1, 'md' => 2]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Permiso')->searchable()->sortable()->weight('medium')->limit(42)->tooltip(fn (Permission $record): string => $record->name),
                Tables\Columns\TextColumn::make('slug')->label('Identificador')->searchable()->copyable()->limit(42)->tooltip(fn (Permission $record): string => $record->slug),
                Tables\Columns\TextColumn::make('module')->label('Módulo')->badge()->color('info')->sortable(),
                Tables\Columns\TextColumn::make('roles_count')->label('Roles')->counts('roles')->alignCenter()->sortable(),
                Tables\Columns\IconColumn::make('is_system')->label('Base')->boolean()->alignCenter(),
            ])
            ->defaultSort('module')
            ->recordTitleAttribute('name')
            ->actions([
                EditAction::make()->iconButton()->tooltip('Editar permiso'),
                DeleteAction::make()->iconButton()->tooltip('Eliminar permiso'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPermissions::route('/'),
            'create' => Pages\CreatePermission::route('/create'),
            'edit' => Pages\EditPermission::route('/{record}/edit'),
        ];
    }
}
