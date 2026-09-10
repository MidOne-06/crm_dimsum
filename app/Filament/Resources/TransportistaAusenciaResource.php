<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TransportistaAusenciaResource\Pages;
use App\Models\TransportistaAusencia;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Ausencias de transportistas -- rango de fechas en que un transportista no
 * entrega. Mientras hay una ausencia vigente, sus locales de titular pasan
 * al suplente en "Registrar entrega", y la entrega del suplente queda
 * auto-marcada como reemplazo con este motivo.
 */
class TransportistaAusenciaResource extends Resource
{
    protected static ?string $model = TransportistaAusencia::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';
    protected static ?string $navigationLabel = 'Ausencias de transportistas';
    protected static ?string $modelLabel = 'Ausencia';
    protected static ?string $pluralModelLabel = 'Ausencias de transportistas';
    protected static string|\UnitEnum|null $navigationGroup = 'Entregas';
    protected static ?int $navigationSort = 11;

    public static function canViewAny(): bool { return (bool) auth()->user()?->hasPermission('entregas-config.manage'); }
    public static function canCreate(): bool { return (bool) auth()->user()?->hasPermission('entregas-config.manage'); }
    public static function canEdit($record): bool { return (bool) auth()->user()?->hasPermission('entregas-config.manage'); }
    public static function canDelete($record): bool { return (bool) auth()->user()?->hasPermission('entregas-config.manage'); }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Select::make('user_id')
                ->label('Transportista')
                ->options(fn (): array => LocalTransportistaResource::transportistaOptions())
                ->searchable()->native(false)->required(),
            DatePicker::make('fecha_inicio')->label('Desde')->native(false)->required()
                ->beforeOrEqual('fecha_fin'),
            DatePicker::make('fecha_fin')->label('Hasta')->native(false)->required()
                ->afterOrEqual('fecha_inicio'),
            TextInput::make('motivo')->label('Motivo')->required()->maxLength(120)
                ->datalist(['Vacaciones', 'Licencia médica', 'Permiso personal', 'Descanso', 'Reasignación']),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('transportista.name')->label('Transportista')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('fecha_inicio')->label('Desde')->date('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('fecha_fin')->label('Hasta')->date('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('motivo')->label('Motivo')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('estado')->label('Estado')
                    ->state(fn (TransportistaAusencia $r): string => $r->estado())
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'vigente' => 'Vigente', 'proxima' => 'Próxima', default => 'Pasada',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'vigente' => 'danger', 'proxima' => 'warning', default => 'gray',
                    }),
            ])
            ->defaultSort('fecha_inicio', 'desc')
            ->filters([
                SelectFilter::make('user_id')->label('Transportista')
                    ->options(fn (): array => LocalTransportistaResource::transportistaOptions())->searchable(),
                Filter::make('vigentes')->label('Solo vigentes')
                    ->query(fn ($query) => $query->whereDate('fecha_inicio', '<=', today())->whereDate('fecha_fin', '>=', today())),
            ])
            ->actions([
                EditAction::make()->iconButton()->tooltip('Editar'),
                DeleteAction::make()->iconButton()->tooltip('Eliminar'),
            ])
            ->emptyStateHeading('Sin ausencias cargadas.');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListTransportistaAusencias::route('/')];
    }
}
