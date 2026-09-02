<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PrioridadLocalProrrateoResource\Pages;
use App\Models\KardexMovimiento;
use App\Models\PrioridadLocalProrrateo;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Orden de prioridad manual por local (Directiva de Transferencia, Fase 0)
 * -- se usa solo cuando la estrategia de prorrateo (Configuración de
 * prorrateo) está en "manual".
 */
class PrioridadLocalProrrateoResource extends Resource
{
    protected static ?string $model = PrioridadLocalProrrateo::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-numbered-list';
    protected static ?string $navigationLabel = 'Prioridad manual de reparto';
    protected static ?string $modelLabel = 'Prioridad de local';
    protected static ?string $pluralModelLabel = 'Prioridad manual de reparto';
    protected static string|\UnitEnum|null $navigationGroup = 'Configuración DT';
    protected static ?int $navigationSort = 30;

    public static function canViewAny(): bool { return (bool) auth()->user()?->hasPermission('tapers.manage'); }
    public static function canCreate(): bool { return (bool) auth()->user()?->hasPermission('tapers.manage'); }
    public static function canEdit($record): bool { return (bool) auth()->user()?->hasPermission('tapers.manage'); }
    public static function canDelete($record): bool { return (bool) auth()->user()?->hasPermission('tapers.manage'); }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make()->schema([
                Select::make('local_id')
                    ->label('Local')
                    ->options(fn (): array => static::localOptions())
                    ->searchable()->native(false)->required()->live()
                    ->unique(ignoreRecord: true)
                    ->afterStateUpdated(fn (callable $set, ?string $state) => $set('local_nombre', $state ? (static::localOptions()[$state] ?? null) : null)),
                TextInput::make('local_nombre')->label('Nombre (referencia)')->readOnly()->required(),
                TextInput::make('orden')->label('Orden de prioridad')->numeric()->minValue(1)->required()->helperText('Menor número = más prioridad al repartir si FABRICA no alcanza.'),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('orden')->label('Prioridad')->numeric()->sortable()->badge(),
                Tables\Columns\TextColumn::make('local_nombre')->label('Local')->searchable()->weight('medium'),
            ])
            ->defaultSort('orden')
            ->recordTitleAttribute('local_nombre')
            ->actions([EditAction::make()->iconButton()->tooltip('Editar'), DeleteAction::make()->iconButton()->tooltip('Eliminar')]);
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
        return ['index' => Pages\ListPrioridadLocalProrrateos::route('/')];
    }
}
