<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Services\StockFinalGatewayClient;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Password;
use Throwable;

class UserResource extends Resource
{
    protected static ?string $model = User::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationLabel = 'Usuarios';
    protected static ?string $modelLabel = 'Usuario';
    protected static ?string $pluralModelLabel = 'Usuarios';
    protected static string|\UnitEnum|null $navigationGroup = 'Seguridad';
    protected static ?int $navigationSort = 90;

    /**
     * Seguridad no debe depender únicamente de que el enlace aparezca o no
     * en el menú. Estas comprobaciones también protegen las rutas directas y
     * las acciones CRUD que Filament genera para el recurso.
     */
    public static function canViewAny(): bool
    {
        return static::canManageUsers();
    }

    public static function canCreate(): bool
    {
        return static::canManageUsers();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canManageUsers()
            && static::canManageUserRecord($record);
    }

    public static function canDelete(Model $record): bool
    {
        return static::canManageUsers()
            && static::canManageUserRecord($record)
            && ! $record->is(auth()->user());
    }

    private static function canManageUsers(): bool
    {
        return (bool) auth()->user()?->hasPermission('users.manage');
    }

    private static function canManageUserRecord(Model $record): bool
    {
        if (! $record instanceof User || ! $record->roles()->where('slug', 'superadministrador')->exists()) {
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
                    TextInput::make('name')->label('Nombre')->required()->maxLength(255),
                    TextInput::make('email')->label('Correo')->email()->required()->unique(ignoreRecord: true)->maxLength(255),
                    TextInput::make('password')
                        ->label('Contraseña')
                        ->password()
                        ->revealable()
                        ->rule(Password::defaults())
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->dehydrated(fn (?string $state): bool => filled($state)),
                    Toggle::make('is_active')
                        ->label('Activo')
                        ->default(true)
                        ->disabled(fn (?User $record): bool => $record?->is(auth()->user()) ?? false),
                ])
                ->columns(['default' => 1, 'md' => 2]),
            Section::make('Roles')
                ->schema([
                    Select::make('roles')
                        ->label('Roles asignados')
                        ->relationship(
                            'roles',
                            'name',
                            // Quien no es superadministrador no puede otorgar (ni
                            // conservar en un registro existente) el rol
                            // superadministrador -- de lo contrario cualquier
                            // titular de users.manage podría auto-escalarse a
                            // control total del sistema sin pasar por roles.manage.
                            modifyQueryUsing: fn ($query) => (auth()->user()?->isPanelAdministrator() || auth()->user()?->roles()->where('slug', 'superadministrador')->exists())
                                ? $query
                                : $query->where('slug', '!=', 'superadministrador'),
                        )
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->required(),
                ]),
            Section::make('Locales')
                ->schema([
                    Select::make('local_scope')
                        ->label('Acceso a locales')
                        ->options([
                            'all' => 'Todos los locales de Restaurant',
                            'selected' => 'Locales específicos',
                        ])
                        ->default('all')
                        ->native(false)
                        ->required()
                        ->live(),
                    Select::make('local_ids')
                        ->label('Locales asignados')
                        ->multiple()
                        ->searchable()
                        ->options(fn (): array => self::localOptions())
                        ->visible(fn (Get $get): bool => $get('local_scope') === 'selected')
                        ->required(fn (Get $get): bool => $get('local_scope') === 'selected')
                        ->helperText('Selecciona uno o varios locales de Restaurant.')
                        ->dehydrated(false),
                ]),
        ]);
    }

    /** @return array<string, string> local_id => nombre, tomado del gateway de Stock Final. */
    public static function localOptions(): array
    {
        try {
            return collect(app(StockFinalGatewayClient::class)->locals())
                ->mapWithKeys(fn (array $local): array => [(string) $local['id'] => $local['name']])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nombre')->searchable()->sortable()->weight('medium')->limit(42)->tooltip(fn (User $record): string => $record->name),
                Tables\Columns\TextColumn::make('email')->label('Correo')->searchable()->sortable()->copyable()->limit(42)->tooltip(fn (User $record): string => $record->email),
                Tables\Columns\TextColumn::make('roles.name')->label('Roles')->badge()->separator(',')->limitList(2)->expandableLimitedList(),
                Tables\Columns\TextColumn::make('locals.local_nombre')->label('Locales')->badge()->color('gray')->separator(',')->limitList(2)->expandableLimitedList()->placeholder('Todos'),
                Tables\Columns\IconColumn::make('is_active')->label('Activo')->boolean()->alignCenter(),
                Tables\Columns\TextColumn::make('updated_at')->label('Actualizado')->dateTime('d/m/Y H:i')->sortable()->toggleable(),
            ])
            ->defaultSort('name')
            ->recordTitleAttribute('name')
            ->actions([
                Action::make('restablecerCredencialesTerminal')
                    ->label('Credenciales')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->tooltip('Ver y restablecer credenciales del terminal')
                    ->visible(fn (User $record): bool => $record->roles()->where('slug', 'terminal')->exists())
                    ->modalHeading('Credenciales de terminal')
                    ->modalDescription('Por seguridad, las contraseñas existentes no se pueden recuperar. La contraseña mostrada se aplicará al confirmar.')
                    ->modalWidth('5xl')
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->modalSubmitActionLabel('Restablecer contraseña')
                    ->modalCancelActionLabel('Cancelar')
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 2])
                            ->schema([
                                TextInput::make('email')
                                    ->label('Correo')
                                    ->default(fn (User $record): string => $record->email)
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->copyable(),
                                TextInput::make('password_preview')
                                    ->label('Nueva contraseña')
                                    ->default(fn (User $record): string => static::terminalPasswordFor($record))
                                    ->password()
                                    ->revealable()
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->copyable()
                                    ->helperText('Usa el icono del ojo para verla y el de copiar para entregarla al terminal.'),
                            ]),
                    ])
                    ->action(function (User $record): void {
                        $record->forceFill([
                            'password' => static::terminalPasswordFor($record),
                        ])->save();

                        Notification::make()
                            ->title('Contraseña de terminal restablecida')
                            ->body('La nueva contraseña quedó disponible en el modal para copiarla.')
                            ->success()
                            ->send();
                    }),
                EditAction::make()->iconButton()->tooltip('Editar usuario'),
                DeleteAction::make()->iconButton()->tooltip('Eliminar usuario'),
            ])
            ->paginated([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    private static function terminalPasswordFor(User $user): string
    {
        $localName = (string) ($user->locals()->value('local_nombre') ?? 'Terminal');
        $base = preg_replace('/^DIM\s+SUM\s+/iu', '', trim($localName)) ?? $localName;
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT', $base) ?: $base;
        $words = preg_replace('/[^A-Za-z0-9]+/', ' ', $ascii) ?? $ascii;

        return str_replace(' ', '', ucwords(strtolower(trim($words)))) . '#Terminal2026!';
    }
}
