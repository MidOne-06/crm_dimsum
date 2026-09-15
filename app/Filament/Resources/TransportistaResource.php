<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TransportistaResource\Pages;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Password;

class TransportistaResource extends Resource
{
    protected static ?string $model = User::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-identification';
    protected static ?string $navigationLabel = 'Transportistas';
    protected static ?string $modelLabel = 'Transportista';
    protected static ?string $pluralModelLabel = 'Transportistas';
    protected static string|\UnitEnum|null $navigationGroup = 'Entregas';
    protected static ?int $navigationSort = 9;

    public static function canViewAny(): bool
    {
        return static::canManageTransportistas();
    }

    public static function canCreate(): bool
    {
        return static::canManageTransportistas();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canManageTransportistas() && $record instanceof User && $record->esTransportista();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('roles', fn (Builder $query) => $query->where('slug', 'transportista'));
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
                        ->maxLength(255),
                    TextInput::make('email')
                        ->label('Correo')
                        ->email()
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),
                    TextInput::make('password')
                        ->label('Contraseña')
                        ->password()
                        ->revealable()
                        ->rule(Password::defaults())
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->dehydrated(fn (?string $state): bool => filled($state)),
                    Toggle::make('is_active')
                        ->label('Activo')
                        ->default(true),
                ])
                ->columns(['default' => 1, 'md' => 2]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nombre')->searchable()->sortable()->weight('medium'),
                Tables\Columns\TextColumn::make('email')->label('Correo')->searchable()->sortable()->copyable(),
                Tables\Columns\TextColumn::make('asignacionesTransportista.local_nombre')
                    ->label('Locales')
                    ->badge()
                    ->color('gray')
                    ->separator(',')
                    ->limitList(2)
                    ->expandableLimitedList()
                    ->placeholder('Sin asignar'),
                Tables\Columns\IconColumn::make('is_active')->label('Activo')->boolean()->alignCenter(),
                Tables\Columns\TextColumn::make('updated_at')->label('Actualizado')->dateTime('d/m/Y H:i')->sortable()->toggleable(),
            ])
            ->defaultSort('name')
            ->recordTitleAttribute('name')
            ->actions([
                EditAction::make()
                    ->iconButton()
                    ->tooltip('Editar transportista')
                    ->modalWidth('xl')
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->modalSubmitActionLabel('Guardar')
                    ->modalCancelActionLabel('Cancelar'),
            ])
            ->paginated([10, 25, 50]);
    }

    public static function createTransportista(array $data): User
    {
        $transportista = User::create($data);
        $roleId = Role::query()->where('slug', 'transportista')->value('id');

        abort_unless($roleId, 500, 'No existe el rol de transportista.');
        $transportista->roles()->syncWithoutDetaching([$roleId]);

        return $transportista;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTransportistas::route('/'),
        ];
    }

    private static function canManageTransportistas(): bool
    {
        return (bool) auth()->user()?->hasPermission('entregas-config.manage');
    }
}
