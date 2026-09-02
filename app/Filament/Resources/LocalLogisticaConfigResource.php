<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LocalLogisticaConfigResource\Pages;
use App\Models\KardexMovimiento;
use App\Models\LocalLogisticaConfig;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Configuración operativa por local (Directiva de Transferencia, Fase 0):
 * cadencia de reparto, hora de llegada, ventana de recepción, inactividad
 * temporal y modo de arranque para un local nuevo. Sin "ruta" como
 * entidad -- la agrupación de despacho de cada día se calcula sola a
 * partir de frecuencia_dias, no de un catálogo de rutas.
 */
class LocalLogisticaConfigResource extends Resource
{
    protected static ?string $model = LocalLogisticaConfig::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';
    protected static ?string $navigationLabel = 'Logística por local';
    protected static ?string $modelLabel = 'Configuración de local';
    protected static ?string $pluralModelLabel = 'Logística por local';
    protected static string|\UnitEnum|null $navigationGroup = 'Configuración DT';
    protected static ?int $navigationSort = 23;

    public static function canViewAny(): bool { return (bool) auth()->user()?->hasPermission('tapers.manage'); }
    public static function canCreate(): bool { return (bool) auth()->user()?->hasPermission('tapers.manage'); }
    public static function canEdit($record): bool { return (bool) auth()->user()?->hasPermission('tapers.manage'); }
    public static function canDelete($record): bool { return (bool) auth()->user()?->hasPermission('tapers.manage'); }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Local')->schema([
                Select::make('local_id')
                    ->label('Local')
                    ->options(fn (): array => static::localOptions())
                    ->searchable()->native(false)->required()->live()
                    ->afterStateUpdated(fn (callable $set, ?string $state) => $set('local_nombre', $state ? (static::localOptions()[$state] ?? null) : null)),
                TextInput::make('local_nombre')->label('Nombre (referencia)')->readOnly()->required(),
            ])->columns(2),
            Section::make('Cadencia y llegada')->schema([
                TextInput::make('frecuencia_dias')->label('Cada cuántos días le toca reparto')->numeric()->minValue(1)->default(1)->required()
                    ->helperText('1 = diario, 2 = cada 2 días, 3 = cada 3 días...'),
                TimePicker::make('hora_llegada_estimada')->label('Hora de llegada estimada')->seconds(false),
                TimePicker::make('ventana_recepcion_inicio')->label('Puede recibir desde')->seconds(false),
                TimePicker::make('ventana_recepcion_fin')->label('Puede recibir hasta')->seconds(false),
            ])->columns(2),
            Section::make('Inactividad temporal')->description('Si se llena, no se genera sugerencia ni despacho para este local en ese rango -- lo que le tocaba se redistribuye entre el resto según la estrategia de prorrateo vigente.')->schema([
                DatePicker::make('inactivo_desde')->label('Inactivo desde')->native(false),
                DatePicker::make('inactivo_hasta')->label('Inactivo hasta')->native(false),
                TextInput::make('inactivo_motivo')->label('Motivo')->maxLength(120)->columnSpanFull(),
            ])->columns(2)->collapsible()->collapsed(),
            Section::make('Arranque si es local nuevo')->schema([
                Select::make('modo_arranque')
                    ->label('Cómo calcular la cantidad inicial')
                    ->options(['gemelo' => 'Copiar de un local gemelo', 'estandar' => 'Cantidad estándar de arranque', 'manual' => 'Entrada manual, sin fórmula'])
                    ->default('manual')->native(false)->required()->live(),
                Select::make('local_gemelo_id')
                    ->label('Local gemelo de referencia')
                    ->options(fn (): array => static::localOptions())
                    ->searchable()->native(false)
                    ->visible(fn (callable $get) => $get('modo_arranque') === 'gemelo'),
            ])->columns(2)->collapsible()->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('local_nombre')->label('Local')->searchable()->weight('medium')->sortable(),
                Tables\Columns\TextColumn::make('frecuencia_dias')->label('Frecuencia')->formatStateUsing(fn ($s) => $s == 1 ? 'Diario' : "Cada {$s} días")->badge(),
                Tables\Columns\TextColumn::make('hora_llegada_estimada')->label('Llegada')->time('H:i'),
                Tables\Columns\TextColumn::make('ventana_recepcion_inicio')->label('Ventana')->state(fn (LocalLogisticaConfig $r) => $r->ventana_recepcion_inicio && $r->ventana_recepcion_fin ? substr($r->ventana_recepcion_inicio, 0, 5).'–'.substr($r->ventana_recepcion_fin, 0, 5) : '—'),
                Tables\Columns\TextColumn::make('inactivo_hasta')->label('Inactivo')->state(fn (LocalLogisticaConfig $r) => $r->inactivoEn() ? 'Hasta '.$r->inactivo_hasta->format('d/m/Y') : '—')->color(fn (LocalLogisticaConfig $r) => $r->inactivoEn() ? 'danger' : 'gray')->badge(),
                Tables\Columns\TextColumn::make('modo_arranque')->label('Modo arranque')->formatStateUsing(fn ($s) => ucfirst((string) $s))->toggleable(),
            ])
            ->defaultSort('local_nombre')
            ->recordTitleAttribute('local_nombre')
            ->actions([
                EditAction::make()->iconButton()->tooltip('Editar'),
                DeleteAction::make()->iconButton()->tooltip('Eliminar'),
            ]);
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
        return ['index' => Pages\ListLocalLogisticaConfigs::route('/')];
    }
}
