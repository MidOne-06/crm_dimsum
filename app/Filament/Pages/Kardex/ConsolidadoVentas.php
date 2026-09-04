<?php

namespace App\Filament\Pages\Kardex;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Models\KardexMovimiento;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ConsolidadoVentas extends Page implements HasTable
{
    use InteractsWithTable;
    use ScopesLocalsToUser;

    private const MOTIVO_VENTA = 'SALIDA, POR VENTA.';

    private const ALMACEN_PRINCIPAL = 'Almacen Principal';

    private const ALL_LOCALES_OPTION = '__all_locales__';

    private const MAX_FECHAS_COMPARAR = 6;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationLabel = 'Consolidado de ventas';

    protected static ?string $title = 'Consolidado de ventas';

    protected static string|\UnitEnum|null $navigationGroup = 'Kardex';

    protected static ?int $navigationSort = 35;

    protected static ?string $slug = 'kardex/consolidado-ventas';

    protected string $view = 'filament.pages.kardex.consolidado-ventas';

    /** @var array<string, string> */
    public array $localOptions = [];

    /** @var array<string, string> */
    public array $unidadOptions = [];

    public ?array $data = [];

    /** @var array<string, mixed> */
    public array $summary = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('kardex.consolidado-ventas.view');
    }

    public function mount(): void
    {
        $base = KardexMovimiento::query()
            ->where('motivo', self::MOTIVO_VENTA)
            ->where('almacen', self::ALMACEN_PRINCIPAL)
            ->where('salida', '>', 0);

        $this->localOptions = $this->scopeKeyedLocalsToUser((clone $base)
            ->select('local_id', 'local_nombre')
            ->distinct()
            ->orderBy('local_nombre')
            ->get()
            ->pluck('local_nombre', 'local_id')
            ->all());

        $this->unidadOptions = (clone $base)
            ->whereNotNull('unidad_medida')
            ->where('unidad_medida', '!=', '')
            ->distinct()
            ->orderBy('unidad_medida')
            ->pluck('unidad_medida', 'unidad_medida')
            ->all();

        $latest = (clone $base)->max('fecha');
        $latest = $latest ? Carbon::parse($latest) : now();

        $this->form->fill([
            'compararFechas' => false,
            'fecha' => $latest->toDateString(),
            'selectedLocals' => [],
            'fechasComparar' => [
                ['fecha' => $latest->copy()->subDay()->toDateString()],
                ['fecha' => $latest->toDateString()],
            ],
            'localComparar' => self::ALL_LOCALES_OPTION,
            'unidadMedida' => array_key_exists('UNIDAD', $this->unidadOptions) ? 'UNIDAD' : array_key_first($this->unidadOptions),
        ]);

        $this->refreshSummary();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Toggle::make('compararFechas')
                    ->label('Comparar días específicos')
                    ->hintIcon('heroicon-m-information-circle', 'En vez de un local por columna, cada columna es una de las fechas elegidas -- para un mismo local (o todos sumados).')
                    ->live(),
                Grid::make(['default' => 1, 'md' => 3])
                    ->visible(fn (Get $get): bool => ! $get('compararFechas'))
                    ->schema([
                        DatePicker::make('fecha')
                            ->label('Fecha')
                            ->required()
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                        Select::make('unidadMedida')
                            ->label('Unidad de medida')
                            ->options($this->unidadOptions)
                            ->required()
                            ->native(false),
                        Select::make('selectedLocals')
                            ->label('Locales')
                            ->options($this->localSelectOptions())
                            ->multiple()
                            ->native(false)
                            ->searchable()
                            ->optionsLimit(10)
                            ->placeholder('Selecciona locales o Todos'),
                    ]),
                Grid::make(['default' => 1, 'md' => 2])
                    ->visible(fn (Get $get): bool => (bool) $get('compararFechas'))
                    ->schema([
                        Repeater::make('fechasComparar')
                            ->label('Fechas a comparar')
                            ->schema([
                                DatePicker::make('fecha')
                                    ->label('Fecha')
                                    ->required()
                                    ->native(false)
                                    ->displayFormat('d/m/Y'),
                            ])
                            ->addActionLabel('Agregar fecha')
                            ->minItems(2)
                            ->maxItems(self::MAX_FECHAS_COMPARAR)
                            ->reorderable(false)
                            ->columnSpan(1),
                        Grid::make(1)
                            ->schema([
                                Select::make('localComparar')
                                    ->label('Local')
                                    ->options($this->localSelectOptions())
                                    ->required()
                                    ->native(false)
                                    ->searchable()
                                    ->hintIcon('heroicon-m-information-circle', '"Todos los locales" suma la cadena completa por fecha.'),
                                Select::make('unidadMedida')
                                    ->label('Unidad de medida')
                                    ->options($this->unidadOptions)
                                    ->required()
                                    ->native(false),
                            ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function diaAnterior(): void
    {
        $this->data['fecha'] = Carbon::parse($this->data['fecha'] ?? now())->subDay()->toDateString();
        $this->buscar();
    }

    public function diaSiguiente(): void
    {
        $this->data['fecha'] = Carbon::parse($this->data['fecha'] ?? now())->addDay()->toDateString();
        $this->buscar();
    }

    public function buscar(): void
    {
        $this->refreshSummary();
        $this->resetPage();
    }

    public function comparando(): bool
    {
        return (bool) ($this->data['compararFechas'] ?? false);
    }

    public function fechaLabel(): string
    {
        return Carbon::parse($this->data['fecha'] ?? now())->translatedFormat('l d \d\e F, Y');
    }

    public function table(Table $table): Table
    {
        if ($this->comparando()) {
            return $table
                ->query($this->comparativoQuery())
                ->heading('Unidades vendidas por producto -- comparativo de fechas')
                ->columns([
                    TextColumn::make('cod_interno')->label('Código')->searchable(),
                    TextColumn::make('item_nombre')->label('Producto')->searchable()->wrap(),
                    TextColumn::make('unidad')->label('Unidad')->badge(),
                    ...$this->fechaColumns(),
                ])
                ->defaultSort('cod_interno')
                ->defaultKeySort(false)
                ->paginated([10, 25, 50])
                ->defaultPaginationPageOption(25)
                ->emptyStateHeading('No hay ventas registradas para las fechas elegidas.');
        }

        return $table
            ->query($this->matrixQuery())
            ->heading('Unidades vendidas por producto y local')
            ->columns([
                TextColumn::make('cod_interno')->label('Código')->searchable(),
                TextColumn::make('item_nombre')->label('Producto')->searchable()->wrap(),
                TextColumn::make('total')
                    ->label('Total')
                    ->state(fn (KardexMovimiento $record): string => number_format((float) $record->total, 0))
                    ->alignEnd()
                    ->color('primary'),
                TextColumn::make('unidad')->label('Unidad')->badge(),
                ...$this->matrixLocalColumns(),
            ])
            ->defaultSort('total', 'desc')
            ->defaultKeySort(false)
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('No hay ventas registradas para esta fecha.');
    }

    protected function refreshSummary(): void
    {
        if ($this->comparando()) {
            $fechas = $this->fechasComparar();
            $localComparar = $this->localComparar();
            $query = $this->ventasBaseQuery()
                ->whereIn('fecha', $fechas)
                ->when(
                    $localComparar !== self::ALL_LOCALES_OPTION,
                    fn (Builder $query): Builder => $query->where('local_id', $localComparar),
                )
                ->when(
                    $localComparar === self::ALL_LOCALES_OPTION && auth()->user()?->isRestrictedToLocals(),
                    fn (Builder $query): Builder => $query->whereIn('local_id', array_keys($this->localOptions)),
                );

            $this->summary = [
                'total_unidades' => (float) (clone $query)->sum('salida'),
                'productos' => (clone $query)->distinct('item_id')->count('item_id'),
                'fechas' => count($fechas),
            ];

            return;
        }

        $query = $this->ventasQuery();

        $this->summary = [
            'total_unidades' => (float) (clone $query)->sum('salida'),
            'productos' => (clone $query)->distinct('item_id')->count('item_id'),
            'locales' => (clone $query)->distinct('local_id')->count('local_id'),
        ];
    }

    protected function matrixQuery(): Builder
    {
        $query = $this->ventasQuery()
            ->selectRaw('MIN(id) AS id, MAX(cod_interno) AS cod_interno, item_id, MAX(item_nombre) AS item_nombre, MAX(unidad_medida) AS unidad, COALESCE(SUM(salida), 0) AS total')
            ->groupBy('item_id');

        foreach ($this->matrixLocalIds() as $index => $localId) {
            $query->selectRaw(
                'COALESCE(SUM(CASE WHEN local_id = ? THEN salida ELSE 0 END), 0) AS local_'.$index,
                [$localId],
            );
        }

        return $query->orderByDesc('total');
    }

    protected function comparativoQuery(): Builder
    {
        $fechas = $this->fechasComparar();
        $localComparar = $this->localComparar();

        $query = $this->ventasBaseQuery()
            ->whereIn('fecha', $fechas)
            ->when(
                $localComparar !== self::ALL_LOCALES_OPTION,
                fn (Builder $query): Builder => $query->where('local_id', $localComparar),
            )
            ->when(
                $localComparar === self::ALL_LOCALES_OPTION && auth()->user()?->isRestrictedToLocals(),
                fn (Builder $query): Builder => $query->whereIn('local_id', array_keys($this->localOptions)),
            )
            ->selectRaw('MIN(id) AS id, MAX(cod_interno) AS cod_interno, item_id, MAX(item_nombre) AS item_nombre, MAX(unidad_medida) AS unidad')
            ->groupBy('item_id');

        foreach ($fechas as $index => $fecha) {
            $query->selectRaw(
                "COALESCE(SUM(CASE WHEN fecha = ?::date THEN salida ELSE 0 END), 0) AS fecha_{$index}",
                [$fecha],
            );
        }

        return $query;
    }

    protected function ventasQuery(): Builder
    {
        $selectedLocals = $this->selectedLocalIds();
        $fecha = $this->data['fecha'] ?? now()->toDateString();

        return $this->ventasBaseQuery()
            ->whereDate('fecha', $fecha)
            ->when(filled($selectedLocals), fn (Builder $query): Builder => $query->whereIn('local_id', $selectedLocals))
            ->when(
                auth()->user()?->isRestrictedToLocals() && blank($selectedLocals),
                fn (Builder $query): Builder => $query->whereIn('local_id', array_keys($this->localOptions)),
            );
    }

    protected function ventasBaseQuery(): Builder
    {
        return KardexMovimiento::query()
            ->where('motivo', self::MOTIVO_VENTA)
            ->where('almacen', self::ALMACEN_PRINCIPAL)
            ->where('salida', '>', 0)
            ->where('unidad_medida', $this->data['unidadMedida'] ?? 'UNIDAD');
    }

    /** @return array<int, string> */
    protected function fechasComparar(): array
    {
        return collect($this->data['fechasComparar'] ?? [])
            ->pluck('fecha')
            ->filter(fn ($fecha): bool => filled($fecha))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    protected function localComparar(): string
    {
        $local = $this->data['localComparar'] ?? self::ALL_LOCALES_OPTION;

        return $local === self::ALL_LOCALES_OPTION || array_key_exists($local, $this->localOptions)
            ? (string) $local
            : self::ALL_LOCALES_OPTION;
    }

    /** @return array<int, TextColumn> */
    protected function fechaColumns(): array
    {
        return collect($this->fechasComparar())
            ->map(function (string $fecha, int $index): TextColumn {
                $alias = "fecha_{$index}";

                return TextColumn::make($alias)
                    ->label(Carbon::parse($fecha)->translatedFormat('d M'))
                    ->state(fn (KardexMovimiento $record): string => number_format((float) ($record->{$alias} ?? 0), 0))
                    ->alignEnd();
            })
            ->all();
    }

    /** @return array<int, TextColumn> */
    protected function matrixLocalColumns(): array
    {
        return collect($this->matrixLocalIds())
            ->map(function ($localId, int $index): TextColumn {
                $alias = "local_{$index}";

                return TextColumn::make($alias)
                    ->label($this->compactLocalLabel($this->localOptions[$localId] ?? "Local {$localId}"))
                    ->state(fn (KardexMovimiento $record): string => number_format((float) ($record->{$alias} ?? 0), 0))
                    ->alignEnd();
            })
            ->all();
    }

    /** @return array<int, string|int> */
    protected function matrixLocalIds(): array
    {
        $selected = $this->selectedLocalIds();

        return filled($selected) ? array_values($selected) : $this->defaultMatrixLocalIds();
    }

    /** @return array<int, string|int> */
    protected function defaultMatrixLocalIds(): array
    {
        // Sin selección explícita, se muestran los 8 locales con más venta ese
        // día -- una tabla con las 38 columnas de golpe sería ilegible.
        return $this->ventasQuery()
            ->selectRaw('local_id, COALESCE(SUM(salida), 0) AS ventas')
            ->groupBy('local_id')
            ->orderByDesc('ventas')
            ->limit(8)
            ->pluck('local_id')
            ->all();
    }

    /** @return array<string, string> */
    protected function localSelectOptions(): array
    {
        return [self::ALL_LOCALES_OPTION => 'Todos los locales'] + $this->localOptions;
    }

    /** @return array<int, string|int> */
    protected function selectedLocalIds(): array
    {
        $values = array_values(array_filter((array) ($this->data['selectedLocals'] ?? []), fn ($value): bool => filled($value)));

        if (in_array(self::ALL_LOCALES_OPTION, $values, true)) {
            return array_keys($this->localOptions);
        }

        return $this->restrictLocalIdsToUser($values);
    }

    protected function compactLocalLabel(string $name): string
    {
        return str($name)->replaceStart('DIM SUM ', '')->toString();
    }
}
