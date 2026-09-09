<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LocalDiaSinDtResource\Pages;
use App\Models\KardexMovimiento;
use App\Models\LocalDiaSinDt;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

/**
 * Días de la semana en que un local NO genera Directiva de Transferencia
 * -- ver docblock de la migración y de DirectivaTransferenciaService. Sin
 * EditAction a propósito (no tiene sentido "editar" una excepción de un
 * solo campo -- se borra y se crea de nuevo si hace falta cambiarla).
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
            Select::make('local_id')
                ->label('Local')
                ->options(fn (): array => static::localOptions())
                ->searchable()->native(false)->required()->live(),
            Select::make('dia_semana')
                ->label('Día de la semana sin DT')
                ->options(LocalDiaSinDt::DIAS)
                ->native(false)->required()
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, callable $get) => $rule->where('local_id', $get('local_id')))
                ->validationMessages(['unique' => 'Este local ya tiene ese día marcado como sin DT.'])
                ->helperText('El día siguiente a este tampoco tendrá llegada de transporte -- el despacho del día anterior a este tiene que cubrir ambos.'),
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
        return ['index' => Pages\ListLocalDiasSinDt::route('/')];
    }
}
