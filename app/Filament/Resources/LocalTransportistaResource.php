<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LocalTransportistaResource\Pages;
use App\Models\LocalTransportista;
use App\Models\StockInicialLocal;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

/**
 * Asignación de transportistas a locales -- titular + suplente por local
 * (el índice único (local_id, es_suplente) garantiza 1 de cada). Un
 * transportista ve en "Registrar entrega" todos los locales donde es
 * titular (si no está ausente) o suplente (si el titular está ausente).
 */
class LocalTransportistaResource extends Resource
{
    protected static ?string $model = LocalTransportista::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $navigationLabel = 'Asignación de transportistas';
    protected static ?string $modelLabel = 'Asignación';
    protected static ?string $pluralModelLabel = 'Asignación de transportistas';
    protected static string|\UnitEnum|null $navigationGroup = 'Entregas';
    protected static ?int $navigationSort = 10;

    public static function canViewAny(): bool { return (bool) auth()->user()?->hasPermission('entregas-config.manage'); }
    public static function canCreate(): bool { return (bool) auth()->user()?->hasPermission('entregas-config.manage'); }
    public static function canEdit($record): bool { return (bool) auth()->user()?->hasPermission('entregas-config.manage'); }
    public static function canDelete($record): bool { return (bool) auth()->user()?->hasPermission('entregas-config.manage'); }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Select::make('local_id')
                ->label('Local')
                ->options(fn (): array => static::localOptions())
                ->searchable()->native(false)->required()->live()
                ->afterStateUpdated(fn (callable $set, ?string $state) => $set('local_nombre', $state ? (static::localOptions()[$state] ?? null) : null)),
            Toggle::make('es_suplente')
                ->label('Es suplente')
                ->helperText('Apagado = titular. El local necesita 1 titular; el suplente es opcional pero recomendado.')
                ->live()
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, callable $get) => $rule->where('local_id', $get('local_id')))
                ->validationMessages(['unique' => 'Este local ya tiene asignado ese rol -- editá la asignación existente.']),
            Select::make('user_id')
                ->label('Transportista')
                ->options(fn (): array => static::transportistaOptions())
                ->searchable()->native(false)->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('local_id')->label('Local')
                    ->formatStateUsing(fn ($state): string => static::localOptions()[$state] ?? (string) $state)
                    ->searchable()->sortable(),
                Tables\Columns\TextColumn::make('es_suplente')->label('Rol')
                    ->formatStateUsing(fn ($state): string => $state ? 'Suplente' : 'Titular')
                    ->badge()->color(fn ($state): string => $state ? 'gray' : 'success')->sortable(),
                Tables\Columns\TextColumn::make('transportista.name')->label('Transportista')->searchable()->sortable(),
            ])
            ->defaultSort('local_id')
            ->actions([
                EditAction::make()->iconButton()->tooltip('Editar'),
                DeleteAction::make()->iconButton()->tooltip('Eliminar'),
            ])
            ->emptyStateHeading('Sin asignaciones -- cargá al menos un titular por local.');
    }

    /** @return array<string, string> */
    public static function localOptions(): array
    {
        return StockInicialLocal::where('estado', 'confirmado')
            ->orderBy('local_nombre')
            ->pluck('local_nombre', 'local_id')
            ->all();
    }

    /** @return array<int, string> */
    public static function transportistaOptions(): array
    {
        return User::whereHas('roles', fn ($q) => $q->where('slug', 'transportista'))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListLocalTransportistas::route('/')];
    }
}
