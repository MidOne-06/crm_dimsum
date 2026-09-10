<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LocalDiaSinDtResource\Pages;
use App\Models\LocalDiaSinDt;
use App\Models\StockInicialLocal;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Días de la semana en que un local NO genera Directiva de Transferencia
 * -- ver docblock de la migración y de DirectivaTransferenciaService. Sin
 * EditAction a propósito (no tiene sentido "editar" una excepción de un
 * solo campo -- se borra y se crea de nuevo si hace falta cambiarla).
 *
 * Pedido explícito del usuario (2026-09-10): el modal admite uno, varios,
 * o TODOS los locales a la vez para el mismo día -- antes solo se podía
 * cargar de a un local por vez. "Todos los locales" y el multi-select usan
 * el mismo universo que ya usa `DirectivaTransferenciaService`
 * (`StockInicialLocal` confirmado, no cualquier local con historial en
 * Kardex como antes) -- cargar una excepción para un local fuera de ese
 * universo no tendría ningún efecto real en el cálculo, así que ya no se
 * ofrece como opción.
 */
class LocalDiaSinDtResource extends Resource
{
    protected static ?string $model = LocalDiaSinDt::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-date-range';
    protected static ?string $navigationLabel = 'Días sin DT';
    protected static ?string $modelLabel = 'Día sin DT';
    protected static ?string $pluralModelLabel = 'Días sin DT';
    protected static string|\UnitEnum|null $navigationGroup = 'Configuración DT';
    protected static ?int $navigationSort = 25;

    public static function canViewAny(): bool { return (bool) auth()->user()?->hasPermission('tapers.manage'); }
    public static function canCreate(): bool { return (bool) auth()->user()?->hasPermission('tapers.manage'); }
    public static function canDelete($record): bool { return (bool) auth()->user()?->hasPermission('tapers.manage'); }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Toggle::make('todos_los_locales')
                ->label('Todos los locales')
                ->live(),
            Select::make('locales')
                ->label('Local(es)')
                ->options(fn (): array => static::localOptions())
                ->multiple()
                ->searchable()
                ->native(false)
                ->visible(fn (callable $get): bool => ! $get('todos_los_locales'))
                ->required(fn (callable $get): bool => ! $get('todos_los_locales')),
            Select::make('dia_semana')
                ->label('Día sin DT')
                ->options(LocalDiaSinDt::DIAS)
                ->native(false)
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('local_id')->label('Local')
                    ->formatStateUsing(fn ($state): string => static::localOptions()[$state] ?? (string) $state)
                    ->searchable()->sortable(),
                Tables\Columns\TextColumn::make('dia_semana')->label('Día sin DT')
                    ->formatStateUsing(fn ($state): string => LocalDiaSinDt::DIAS[$state] ?? (string) $state)
                    ->badge()->color('danger')->sortable(),
            ])
            ->defaultSort('local_id')
            ->actions([
                DeleteAction::make()->iconButton()->tooltip('Eliminar'),
            ])
            ->emptyStateHeading('Sin excepciones cargadas -- todos los locales generan DT los 7 días de la semana.');
    }

    /**
     * Universo de locales real que usa `DirectivaTransferenciaService` --
     * cambiado de "cualquier local con historial en Kardex" a "solo los
     * confirmados en Stock Inicial" (2026-09-10), porque son los únicos
     * que el cálculo real llega a leer.
     *
     * @return array<string, string>
     */
    public static function localOptions(): array
    {
        return StockInicialLocal::where('estado', 'confirmado')
            ->orderBy('local_nombre')
            ->pluck('local_nombre', 'local_id')
            ->all();
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListLocalDiasSinDt::route('/')];
    }
}
