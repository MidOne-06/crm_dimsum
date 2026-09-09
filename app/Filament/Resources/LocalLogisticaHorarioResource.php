<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LocalLogisticaHorarioResource\Pages;
use App\Models\KardexMovimiento;
use App\Models\LocalLogisticaHorario;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Excepción de hora de llegada por local + día de la semana -- pedido
 * explícito del usuario (2026-09-09) para que la Directiva de Transferencia
 * pueda cubrir la demanda real con la hora exacta en que llega cada camión,
 * en vez de asumir 12pm fijo para todos los días de todos los locales. Un
 * local sin ninguna fila acá sigue usando
 * LocalLogisticaConfig::hora_llegada_estimada (o 12:00 si tampoco tiene
 * eso) para los 7 días -- esta tabla es solo para EXCEPCIONES puntuales.
 */
class LocalLogisticaHorarioResource extends Resource
{
    protected static ?string $model = LocalLogisticaHorario::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';
    protected static ?string $navigationLabel = 'Horarios por día (Logística)';
    protected static ?string $modelLabel = 'Horario de llegada';
    protected static ?string $pluralModelLabel = 'Horarios por día';
    protected static string|\UnitEnum|null $navigationGroup = 'Configuración DT';
    protected static ?int $navigationSort = 24;

    public static function canViewAny(): bool { return (bool) auth()->user()?->hasPermission('tapers.manage'); }
    public static function canCreate(): bool { return (bool) auth()->user()?->hasPermission('tapers.manage'); }
    public static function canEdit($record): bool { return (bool) auth()->user()?->hasPermission('tapers.manage'); }
    public static function canDelete($record): bool { return (bool) auth()->user()?->hasPermission('tapers.manage'); }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Select::make('local_id')
                ->label('Local')
                ->options(fn (): array => static::localOptions())
                ->searchable()->native(false)->required(),
            Select::make('dia_semana')
                ->label('Día de la semana')
                ->options(LocalLogisticaHorario::DIAS)
                ->native(false)->required(),
            TimePicker::make('hora')->label('Hora de llegada ese día')->seconds(false)->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('local_id')->label('Local')
                    ->formatStateUsing(fn ($state): string => static::localOptions()[$state] ?? (string) $state)
                    ->searchable()->sortable(),
                Tables\Columns\TextColumn::make('dia_semana')->label('Día')
                    ->formatStateUsing(fn ($state): string => LocalLogisticaHorario::DIAS[$state] ?? (string) $state)
                    ->badge()->sortable(),
                Tables\Columns\TextColumn::make('hora')->label('Hora de llegada')->time('H:i')->sortable(),
            ])
            ->defaultSort('local_id')
            ->actions([
                EditAction::make()->iconButton()->tooltip('Editar'),
                DeleteAction::make()->iconButton()->tooltip('Eliminar'),
            ])
            ->emptyStateHeading('Sin excepciones cargadas -- todos los locales usan su hora de llegada estimada (o 12:00) los 7 días.');
    }

    /** @return array<string, string> */
    protected static function localOptions(): array
    {
        return KardexMovimiento::query()
            ->whereNotNull('local_id')
            ->select('local_id', 'local_nombre')
            ->distinct()
            ->orderBy('local_nombre')
            ->pluck('local_nombre', 'local_id')
            ->all();
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListLocalLogisticaHorarios::route('/')];
    }
}
