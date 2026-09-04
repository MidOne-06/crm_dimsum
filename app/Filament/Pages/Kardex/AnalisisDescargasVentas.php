<?php

namespace App\Filament\Pages\Kardex;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Models\KardexMovimiento;
use Carbon\Carbon;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Análisis de ventas operativas desde las salidas de Kardex.
 */
class AnalisisDescargasVentas extends Page implements HasTable
{
    use InteractsWithTable;
    use ScopesLocalsToUser;

    private const MOTIVO_VENTA = 'SALIDA, POR VENTA.';

    private const ALMACEN_PRINCIPAL = 'Almacen Principal';

    private const ALL_LOCALES_OPTION = '__all_locales__';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationLabel = 'Análisis de descargas';

    protected static ?string $title = 'Análisis de descargas por venta';

    protected static string|\UnitEnum|null $navigationGroup = 'Kardex';

    protected static ?int $navigationSort = 33;

    protected static ?string $slug = 'kardex/analisis-descargas-ventas';

    protected string $view = 'filament.pages.kardex.analisis-descargas-ventas';

    public array $localOptions = [];

    public array $unidadOptions = [];

    public array $categoriaOptions = [];

    public ?array $data = [];

    /** @var array<string, mixed> Filtros exclusivos del gráfico de comparación. */
    public ?array $comparisonData = [];

    public string $activeDatePreset = 'month';

    /** @var array<string, mixed> */
    public array $analysisSnapshot = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('kardex.analisis-descargas.view');
    }

    public function mount(): void
    {
        $this->localOptions = $this->scopeKeyedLocalsToUser(KardexMovimiento::query()
            ->where('motivo', self::MOTIVO_VENTA)
            ->where('almacen', self::ALMACEN_PRINCIPAL)
            ->select('local_id', 'local_nombre')
            ->distinct()
            ->orderBy('local_nombre')
            ->get()
            ->pluck('local_nombre', 'local_id')
            ->all());

        $this->unidadOptions = KardexMovimiento::query()
            ->where('motivo', self::MOTIVO_VENTA)
            ->where('almacen', self::ALMACEN_PRINCIPAL)
            ->whereNotNull('unidad_medida')
            ->where('unidad_medida', '!=', '')
            ->distinct()
            ->orderBy('unidad_medida')
            ->pluck('unidad_medida', 'unidad_medida')
            ->all();

        $this->categoriaOptions = KardexMovimiento::query()
            ->where('motivo', self::MOTIVO_VENTA)
            ->where('almacen', self::ALMACEN_PRINCIPAL)
            ->whereNotNull('categoria')
            ->where('categoria', '!=', '')
            ->distinct()
            ->orderBy('categoria')
            ->pluck('categoria', 'categoria')
            ->all();

        $latestDate = $this->latestAvailableSaleDate();

        $this->form->fill([
            'selectedLocals' => [],
            // La unidad es deliberadamente obligatoria: no se mezclan UNIDAD,
            // KILOS y LITRO en una sola métrica operacional.
            'unidadMedida' => array_key_exists('UNIDAD', $this->unidadOptions)
                ? 'UNIDAD'
                : array_key_first($this->unidadOptions),
            'categoria' => '',
            'producto' => '',
        ]);
        $this->comparison->fill([
            'selectedLocals' => [],
            'selectedProducts' => [],
            'comparisonDimension' => 'producto',
        ]);
        $this->data['dateStart'] = $latestDate->copy()->startOfMonth()->toDateString();
        $this->data['dateEnd'] = $latestDate->toDateString();

        $this->refreshAnalysis();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['default' => 1, 'md' => 2, 'xl' => 4])
                    ->schema([
                        Select::make('unidadMedida')
                            ->label('Unidad de medida')
                            ->options($this->unidadOptions)
                            ->required()
                            ->native(false)
                            ->searchable(false),
                        Select::make('categoria')
                            ->label('Categoría')
                            ->options($this->categoriaOptions)
                            ->native(false)
                            ->searchable()
                            ->preload()
                            ->placeholder('Todas las categorías'),
                        TextInput::make('producto')
                            ->label('Producto o código')
                            ->placeholder('Nombre o código interno...')
                            ->maxLength(100),
                        Select::make('selectedLocals')
                            ->label('Locales de la tabla y análisis')
                            ->options($this->localSelectOptions())
                            ->multiple()
                            ->native(false)
                            ->searchable()
                            ->optionsLimit(10)
                            ->placeholder('Selecciona locales o Todos'),
                    ]),
            ])
            ->statePath('data');
    }

    public function comparison(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['default' => 1, 'md' => 2, 'xl' => 3])
                    ->schema([
                        Select::make('selectedLocals')
                            ->label('Locales a comparar')
                            ->options($this->localOptions)
                            ->multiple()
                            ->maxItems(5)
                            ->native(false)
                            ->searchable()
                            ->preload()
                            ->placeholder('Top 5 locales'),
                        Select::make('selectedProducts')
                            ->label('Productos a comparar')
                            ->multiple()
                            ->maxItems(10)
                            ->native(false)
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => $this->productSearchResults($search))
                            ->getOptionLabelsUsing(fn (array $values): array => $this->productLabels($values))
                            ->optionsLimit(50)
                            ->placeholder('Top 10 productos'),
                        Select::make('comparisonDimension')
                            ->label('Mostrar cantidades por')
                            ->options([
                                'producto' => 'Producto',
                                'dia' => 'Día',
                            ])
                            ->required()
                            ->native(false),
                    ]),
            ])
            ->statePath('comparisonData');
    }

    public function syncDateRange(string $start, string $end, string $preset = 'custom'): void
    {
        $this->data['dateStart'] = $start;
        $this->data['dateEnd'] = $end;
        $this->activeDatePreset = $preset;
    }

    /** @return array{0: string, 1: string} */
    public function dateRangeForDisplay(): array
    {
        return [
            (string) ($this->data['dateStart'] ?? now()->startOfMonth()->toDateString()),
            (string) ($this->data['dateEnd'] ?? now()->toDateString()),
        ];
    }

    public function usesHistoricalCoverage(): bool
    {
        return false;
    }

    public function applyDailyPreset(string $preset): void
    {
        $end = $this->latestAvailableSaleDate();
        $start = $end->copy();

        match ($preset) {
            'yesterday' => [$start, $end] = [$end->copy()->subDay(), $end->copy()->subDay()],
            'before_yesterday' => [$start, $end] = [$end->copy()->subDays(2), $end->copy()->subDays(2)],
            'last7' => $start = $end->copy()->subDays(6),
            default => null,
        };

        $this->syncDateRange($start->toDateString(), $end->toDateString(), $preset);
        $this->refreshAnalysis();
        $this->resetPage();
    }

    public function search(): void
    {
        $this->refreshAnalysis();
        $this->resetPage();
    }

    public function abrirFiltros(): void
    {
        $this->dispatch('open-modal', id: 'filtros-analisis-descargas-ventas');
    }

    public function cerrarFiltros(): void
    {
        $this->dispatch('close-modal', id: 'filtros-analisis-descargas-ventas');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->salesMatrixQuery())
            ->heading('Matriz completa de ventas')
            ->description($this->matrixDescription())
            ->columns([
                TextColumn::make('cod_interno')
                    ->label('Código')
                    ->searchable(),
                TextColumn::make('item_nombre')
                    ->label('Producto')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('registros')
                    ->label('Movimientos')
                    ->numeric()
                    ->alignEnd(),
                TextColumn::make('descargas')
                    ->label('Ventas')
                    ->state(fn (KardexMovimiento $record): string => number_format((float) $record->descargas, 0))
                    ->alignEnd()
                    ->color('warning'),
                TextColumn::make('unidad')
                    ->label('Unidad')
                    ->badge(),
                ...$this->matrixLocalColumns(),
            ])
            ->defaultSort('descargas', 'desc')
            // La consulta agrupa por producto y ya no expone el id físico.
            // Evita que Filament añada kardex_movimientos.id al ORDER BY.
            ->defaultKeySort(false)
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('No hay ventas para los filtros elegidos.');
    }

    /** @return array<string, mixed> */
    public function analysis(): array
    {
        return $this->analysisSnapshot;
    }

    protected function refreshAnalysis(): void
    {
        $selectedLocals = $this->selectedLocalIds();
        $comparisonLocals = $this->restrictLocalIdsToUser($this->comparisonData['selectedLocals'] ?? []);
        $query = $this->analysisQuery();
        $totals = (clone $query)->selectRaw(
            'COUNT(*) AS movimientos, COALESCE(SUM(salida), 0) AS descargas, COUNT(DISTINCT local_id) AS locales, COUNT(DISTINCT fecha) AS dias_con_datos'
        )->first();

        $start = Carbon::parse($this->data['dateStart'] ?? now()->toDateString());
        $end = Carbon::parse($this->data['dateEnd'] ?? now()->toDateString());
        if ($end->lessThan($start)) {
            [$start, $end] = [$end, $start];
        }

        $periodDays = $start->diffInDays($end) + 1;
        $totalDescargas = (float) ($totals->descargas ?? 0);
        $this->analysisSnapshot = [
            'movimientos' => (int) ($totals->movimientos ?? 0),
            'descargas' => $totalDescargas,
            'locales' => (int) ($totals->locales ?? 0),
            'dias_con_datos' => (int) ($totals->dias_con_datos ?? 0),
            'dias_periodo' => $periodDays,
            'cobertura' => $periodDays > 0 ? min(100, ((int) ($totals->dias_con_datos ?? 0) / $periodDays) * 100) : 0,
            'promedio_diario' => ((int) ($totals->dias_con_datos ?? 0) > 0)
                ? $totalDescargas / (int) $totals->dias_con_datos
                : 0,
            'unidad' => $this->data['unidadMedida'] ?? '',
            'rango' => [$start->toDateString(), $end->toDateString()],
            'filters' => [
                'selectedLocals' => $selectedLocals,
                'selectedProducts' => [],
                'unidadMedida' => $this->data['unidadMedida'] ?? '',
                'categoria' => $this->data['categoria'] ?? '',
                'producto' => $this->data['producto'] ?? '',
                'dateStart' => $start->toDateString(),
                'dateEnd' => $end->toDateString(),
            ],
            'comparisonFilters' => [
                'selectedLocals' => $this->comparisonLocalIds($selectedLocals, $comparisonLocals),
                'selectedProducts' => $this->comparisonProductIds(),
                'unidadMedida' => $this->data['unidadMedida'] ?? '',
                'categoria' => $this->data['categoria'] ?? '',
                'producto' => $this->data['producto'] ?? '',
                'comparisonDimension' => $this->comparisonData['comparisonDimension'] ?? 'producto',
                'dateStart' => $start->toDateString(),
                'dateEnd' => $end->toDateString(),
            ],
        ];
    }

    protected function analysisQuery(): Builder
    {
        $selectedLocals = $this->selectedLocalIds();
        $start = $this->data['dateStart'] ?? now()->startOfMonth()->toDateString();
        $end = $this->data['dateEnd'] ?? now()->toDateString();
        $unidad = $this->data['unidadMedida'] ?? '';
        $categoria = $this->data['categoria'] ?? '';
        $producto = trim((string) ($this->data['producto'] ?? ''));

        $query = KardexMovimiento::query()
            ->where('motivo', self::MOTIVO_VENTA)
            ->where('almacen', self::ALMACEN_PRINCIPAL)
            ->where('salida', '>', 0)
            ->whereDate('fecha', '>=', $start)
            ->whereDate('fecha', '<=', $end)
            ->when(filled($selectedLocals), fn (Builder $query): Builder => $query->whereIn('local_id', $selectedLocals))
            ->when(filled($unidad), fn (Builder $query): Builder => $query->where('unidad_medida', $unidad))
            ->when(filled($categoria), fn (Builder $query): Builder => $query->where('categoria', $categoria));

        if (auth()->user()?->isRestrictedToLocals() && blank($selectedLocals)) {
            $query->whereIn('local_id', array_keys($this->localOptions));
        }

        if ($producto !== '') {
            $query->where(function (Builder $query) use ($producto): void {
                $query->where('item_nombre', 'ilike', "%{$producto}%")
                    ->orWhere('cod_interno', 'ilike', "%{$producto}%")
                    ->orWhere('producto', 'ilike', "%{$producto}%");
            });
        }

        return $query;
    }

    protected function salesMatrixQuery(): Builder
    {
        $query = $this->analysisQuery()
            ->selectRaw('MIN(id) AS id, MAX(cod_interno) AS cod_interno, item_id, MAX(item_nombre) AS item_nombre, MAX(unidad_medida) AS unidad, COUNT(*) AS registros, COALESCE(SUM(salida), 0) AS descargas')
            ->groupBy('item_id');

        foreach ($this->matrixLocalIds() as $index => $localId) {
            $query->selectRaw(
                sprintf('COALESCE(SUM(CASE WHEN local_id = ? THEN salida ELSE 0 END), 0) AS venta_local_%d', $index),
                [$localId],
            );
        }

        return $query->orderByDesc('descargas');
    }

    /** @return array<int, TextColumn> */
    protected function matrixLocalColumns(): array
    {
        return collect($this->matrixLocalIds())
            ->map(function ($localId, int $index): TextColumn {
                $alias = "venta_local_{$index}";

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
        return $this->analysisQuery()
            ->selectRaw('local_id, COALESCE(SUM(salida), 0) AS ventas')
            ->groupBy('local_id')
            ->orderByDesc('ventas')
            ->limit(5)
            ->pluck('local_id')
            ->all();
    }

    protected function matrixDescription(): string
    {
        if (in_array(self::ALL_LOCALES_OPTION, (array) ($this->data['selectedLocals'] ?? []), true)) {
            return 'Producto por todos los locales permitidos.';
        }

        return blank($this->data['selectedLocals'] ?? [])
            ? 'Producto por local. Mostrando los 5 locales principales; selecciona locales para cambiar la matriz.'
            : 'Producto por los locales seleccionados.';
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

    /** @return array<string, string> */
    public function productSearchResults(string $search): array
    {
        $search = trim($search);

        return $this->analysisQuery()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('item_nombre', 'ilike', "%{$search}%")
                        ->orWhere('cod_interno', 'ilike', "%{$search}%")
                        ->orWhere('producto', 'ilike', "%{$search}%");
                });
            })
            ->selectRaw('item_id, MAX(cod_interno) AS cod_interno, MAX(item_nombre) AS item_nombre')
            ->groupBy('item_id')
            ->orderBy('item_nombre')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn ($row): array => [(string) $row->item_id => trim("{$row->cod_interno} · {$row->item_nombre}", " ·")])
            ->all();
    }

    /** @param array<int, string|int> $values
     *  @return array<string, string>
     */
    public function productLabels(array $values): array
    {
        $ids = array_values(array_filter($values, fn ($value): bool => filled($value)));

        if ($ids === []) {
            return [];
        }

        return KardexMovimiento::query()
            ->whereIn('item_id', $ids)
            ->selectRaw('item_id, MAX(cod_interno) AS cod_interno, MAX(item_nombre) AS item_nombre')
            ->groupBy('item_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [(string) $row->item_id => trim("{$row->cod_interno} · {$row->item_nombre}", " ·")])
            ->all();
    }

    /** @return array<int, string|int> */
    protected function selectedProductIds(): array
    {
        return $this->comparisonProductIds();
    }

    /** @return array<int, string|int> */
    protected function comparisonProductIds(): array
    {
        return array_values(array_filter((array) ($this->comparisonData['selectedProducts'] ?? []), fn ($value): bool => filled($value)));
    }

    /**
     * @param array<int, string|int> $globalLocals
     * @param array<int, string|int> $comparisonLocals
     * @return array<int, string|int>
     */
    protected function comparisonLocalIds(array $globalLocals, array $comparisonLocals): array
    {
        if (blank($comparisonLocals)) {
            return $globalLocals;
        }

        return blank($globalLocals)
            ? array_values($comparisonLocals)
            : array_values(array_intersect($comparisonLocals, $globalLocals));
    }

    protected function latestAvailableSaleDate(): Carbon
    {
        $latest = KardexMovimiento::query()
            ->where('motivo', self::MOTIVO_VENTA)
            ->where('almacen', self::ALMACEN_PRINCIPAL)
            ->where('salida', '>', 0)
            ->max('fecha');

        return $latest ? Carbon::parse($latest)->startOfDay() : now()->startOfDay();
    }

    protected function compactLocalLabel(string $name): string
    {
        return str($name)->replaceStart('DIM SUM ', '')->toString();
    }
}
