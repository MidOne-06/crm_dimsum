<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoleResource\Pages;
use App\Models\Permission;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;
    protected static ?string $recordTitleAttribute = 'name';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';
    protected static ?string $navigationLabel = 'Roles';
    protected static ?string $modelLabel = 'Rol';
    protected static ?string $pluralModelLabel = 'Roles';
    protected static string|\UnitEnum|null $navigationGroup = 'Seguridad';
    protected static ?int $navigationSort = 91;

    public static function canViewAny(): bool
    {
        return static::canManageRoles();
    }

    public static function canCreate(): bool
    {
        return static::canManageRoles();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canManageRoles() && static::canManageRoleRecord($record);
    }

    public static function canDelete(Model $record): bool
    {
        return static::canManageRoles() && static::canManageRoleRecord($record);
    }

    private static function canManageRoles(): bool
    {
        return (bool) auth()->user()?->hasPermission('roles.manage');
    }

    /** Los roles base, en especial Superadministrador, solo los modifica un superadministrador. */
    private static function canManageRoleRecord(Model $record): bool
    {
        if (! $record instanceof Role || ! $record->is_system) {
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
                ->columnSpanFull()
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
                ->columnSpanFull()
                ->schema([
                    Select::make('permissions')
                        ->label('Permisos asignados')
                        ->relationship(
                            'permissions',
                            'name',
                            // Auditoría 2026-09-16: sin esto, cualquier titular de
                            // roles.manage podía asignar users.manage/roles.manage/
                            // permissions.manage a un rol no-system existente y
                            // luego asignarse ese rol (con users.manage) -- una
                            // escalación de privilegios en 2 pasos. Mismo criterio
                            // que ya protege el rol superadministrador en
                            // UserResource: sin acceso de superadministrador, estos
                            // 3 permisos ni siquiera se ofrecen como opción.
                            modifyQueryUsing: fn ($query) => (auth()->user()?->isPanelAdministrator() || auth()->user()?->roles()->where('slug', 'superadministrador')->exists())
                                ? $query
                                : $query->whereNotIn('slug', ['users.manage', 'roles.manage', 'permissions.manage']),
                        )
                        ->getOptionLabelFromRecordUsing(fn (Permission $record): string => trim(($record->module ? $record->module.' · ' : '').$record->name))
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->optionsLimit(100),
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
                EditAction::make()->iconButton()->tooltip('Editar rol')->modalWidth('xl')->stickyModalHeader()->stickyModalFooter()->modalSubmitActionLabel('Guardar')->modalCancelActionLabel('Cancelar'),
                DeleteAction::make()->iconButton()->tooltip('Eliminar rol'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
        ];
    }
}
