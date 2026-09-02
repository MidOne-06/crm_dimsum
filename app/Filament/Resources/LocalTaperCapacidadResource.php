<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LocalTaperCapacidadResource\Pages;
use App\Models\KardexMovimiento;
use App\Models\LocalTaperCapacidad;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

/**
 * Máximo de tapers de un tipo dado que caben en la congeladora de cada local
 * (Directiva de Transferencia, Fase 0). La suma de tapers de todos los
 * productos enviados a un local no debe superar este tope por tipo.
 */
class LocalTaperCapacidadResource extends Resource
{
    protected static ?string $model = LocalTaperCapacidad::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';
    protected static ?string $navigationLabel = 'Capacidad por local';
    protected static ?string $modelLabel = 'Capacidad de local';
    protected static ?string $pluralModelLabel = 'Capacidades por local';
    protected static string|\UnitEnum|null $navigationGroup = 'Configuración DT';
    protected static ?int $navigationSort = 22;

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->hasPermission('tapers.manage');
    }

    public static function canCreate(): bool
    {
        return (bool) auth()->user()?->hasPermission('tapers.manage');
    }

    public static function canEdit($record): bool
    {
        return (bool) auth()->user()?->hasPermission('tapers.manage');
    }

    public static function canDelete($record): bool
    {
        return (bool) auth()->user()?->hasPermission('tapers.manage');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Grid::make(['default' => 1, 'md' => 2, 'xl' => 4])
                ->columnSpanFull()
                ->schema([
                    Select::make('local_id')
                        ->label('Local')
                        ->options(fn (): array => static::localOptions())
                        ->searchable()
                        ->native(false)
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (callable $set, ?string $state): void {
                            $set('local_nombre', $state ? (static::localOptions()[$state] ?? null) : null);
                        })
                        ->columnSpan(['xl' => 2]),
                    Select::make('taper_tipo_id')
                        ->label('Tipo de taper')
                        ->relationship('taperTipo', 'nombre')
                        ->native(false)
                        ->searchable()
                        ->preload()
                        ->required()
                        ->unique(
                            table: 'local_taper_capacidades',
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $rule, callable $get) => $rule->where('local_id', $get('local_id')),
                        )
                        ->columnSpan(1),
                    TextInput::make('capacidad_maxima')
                        ->label('Tapers máximos que caben')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->columnSpan(1),
                    TextInput::make('local_nombre')->readOnly()->required()->hidden(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('local_nombre')->label('Local')->searchable()->weight('medium')->sortable(),
                Tables\Columns\TextColumn::make('taperTipo.nombre')->label('Tipo de taper')->badge()->sortable(),
                Tables\Columns\TextColumn::make('capacidad_maxima')->label('Tapers máximos')->numeric()->alignEnd()->sortable(),
            ])
            ->defaultSort('local_nombre')
            ->recordTitleAttribute('local_nombre')
            ->actions([
                EditAction::make()->iconButton()->tooltip('Editar capacidad')->modalWidth('5xl')->stickyModalHeader()->stickyModalFooter(),
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

    /** Sin rutas 'create'/'edit': se manejan en modal sobre la lista. */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLocalTaperCapacidades::route('/'),
        ];
    }
}
