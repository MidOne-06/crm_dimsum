<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VehiculoCapacidadResource\Pages;
use App\Models\VehiculoCapacidad;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/** Tope físico (en tapers) de un tipo de vehículo/viaje (Directiva de Transferencia, Fase 0). */
class VehiculoCapacidadResource extends Resource
{
    protected static ?string $model = VehiculoCapacidad::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';
    protected static ?string $navigationLabel = 'Capacidad de vehículos';
    protected static ?string $modelLabel = 'Vehículo';
    protected static ?string $pluralModelLabel = 'Capacidad de vehículos';
    protected static string|\UnitEnum|null $navigationGroup = 'Configuración DT';
    protected static ?int $navigationSort = 27;

    public static function canViewAny(): bool { return (bool) auth()->user()?->hasPermission('tapers.manage'); }
    public static function canCreate(): bool { return (bool) auth()->user()?->hasPermission('tapers.manage'); }
    public static function canEdit($record): bool { return (bool) auth()->user()?->hasPermission('tapers.manage'); }
    public static function canDelete($record): bool { return (bool) auth()->user()?->hasPermission('tapers.manage'); }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make()->schema([
                TextInput::make('nombre')->label('Nombre')->required()->maxLength(80)->placeholder('Ej. Camioneta refrigerada 1'),
                TextInput::make('capacidad_maxima_tapers')->label('Tapers máximos por viaje')->numeric()->minValue(1)->required(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('nombre')->label('Nombre')->searchable()->weight('medium')->sortable(),
                Tables\Columns\TextColumn::make('capacidad_maxima_tapers')->label('Tapers máximos por viaje')->numeric()->alignEnd()->sortable(),
            ])
            ->defaultSort('nombre')
            ->recordTitleAttribute('nombre')
            ->actions([EditAction::make()->iconButton()->tooltip('Editar'), DeleteAction::make()->iconButton()->tooltip('Eliminar')]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListVehiculoCapacidads::route('/')];
    }
}
