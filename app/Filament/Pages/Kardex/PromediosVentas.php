<?php

namespace App\Filament\Pages\Kardex;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Models\KardexMovimiento;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
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

class PromediosVentas extends Page implements HasTable
{
    use InteractsWithTable;
    use ScopesLocalsToUser;

    private const MOTIVO_VENTA = 'SALIDA, POR VENTA.';

    private const ALMACEN_PRINCIPAL = 'Almacen Principal';

    private const ALL_LOCALES_OPTION = '__all_locales__';

    private const STANDARD_PRODUCTS_OPTION = '__standard_products__';

    /** @var array<string, array{code: string, name: string}> */
    private const STANDARD_PRODUCTS = [
        '153' => ['code' => 'SM001', 'name' => 'Siu Mai - cerdo'],
        '106' => ['code' => 'SM002', 'name' => 'Siu Mai Especial'],
        '157' => ['code' => 'SM003', 'name' => 'Siu Mai - pollo'],
        '147' => ['code' => 'WK001', 'name' => 'Wo Ti Kao'],
        '137' => ['code' => 'MP001', 'name' => 'Min Pao - Pollo'],
        '118' => ['code' => 'MP002', 'name' => 'Min Pao - Chancho'],
        '159' => ['code' => 'MP003', 'name' => 'Min Pao - Dulce'],
        '138' => ['code' => 'MP004', 'name' => 'Min Pao - Mixto'],
        '156' => ['code' => 'ER001', 'name' => 'Enrollado Primavera Tradicional'],
        '105' => ['code' => 'AA003', 'name' => 'Ala Asada'],
        '144' => ['code' => 'AB001', 'name' => 'Ala Broaster'],
        '158' => ['code' => 'KP001', 'name' => 'Kai Pi'],
        '155' => ['code' => 'WT001', 'name' => 'Wantan'],
        '154' => ['code' => 'SK001', 'name' => 'Siu Kao Frito'],
        '143' => ['code' => 'TP001', 'name' => 'Tay Pao'],
        '161' => ['code' => 'CS001', 'name' => 'Cha Siu 1/4 KG.'],
        '160' => ['code' => 'CH001', 'name' => 'Chaufa x 260 gr'],
    ];

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationLabel = 'Promedios de venta';

    protected static ?string $title = 'Promedios de venta';

    protected static string|\UnitEnum|null $navigationGroup = 'Kardex';

    protected static ?int $navigationSort = 34;

    protected static ?string $slug = 'kardex/promedios-ventas';

    protected string $view = 'filament.pages.kardex.promedios-ventas';

    /** @var array<string, string> */
    public array $localOptions = [];

    /** @var array<string, string> */
    public array $unidadOptions = [];

    public ?array $data = [];

    public string $activeDatePreset = 'last30';

    /** @var array<string, mixed> */
    public array $summary = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('kardex.promedios-ventas.view');
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
            'selectedLocals' => [],
            // La matriz inicia con el catálogo solicitado, pero el usuario puede
            // retirar o añadir productos desde el selector dinámico.
            // Un solo selector visible evita inundar la pantalla con todas las
            // etiquetas; internamente se expande al catálogo estándar.
            'selectedProducts' => [self::STANDARD_PRODUCTS_OPTION],
            'unidadMedida' => array_key_exists('UNIDAD', $this->unidadOptions) ? 'UNIDAD' : array_key_first($this->unidadOptions),
            'averageMode' => 'daily',
            'weekday' => '1',
            'month' => (string) $latest->month,
            'calculationMethod' => 'weighted',
            'recentWeeks' => '8',
            'demandWindow' => 'full_day',
            'showStockoutWarning' => true,
        ]);
        // El modo "Día del rango" debe abrir rápido. El análisis histórico se
        // conserva al elegir día de semana o mes, que no dependen de este campo.
        $this->data['dateStart'] = $latest->copy()->subDays(29)->toDateString();
        $this->data['dateEnd'] = $latest->toDateString();

        $this->refreshSummary();
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
                            ->native(false),
                        Select::make('averageMode')
                            ->label('Calcular promedio por')
                            ->options([
                                'daily' => 'Día del rango',
                                'weekday' => 'Día de semana',
                                'month' => 'Mes',
                            ])
                            ->required()
                            ->native(false)
                            ->live(),
                        Select::make('weekday')
                            ->label('Día de semana')
                            ->options([
                                '1' => 'Lunes', '2' => 'Martes', '3' => 'Miércoles',
                                '4' => 'Jueves', '5' => 'Viernes', '6' => 'Sábado', '0' => 'Domingo',
                            ])
                            ->native(false)
                            ->visible(fn (Get $get): bool => $get('averageMode') === 'weekday'),
                        Select::make('month')
                            ->label('Mes')
                            ->options([
                                '1' => 'Enero', '2' => 'Febrero', '3' => 'Marzo', '4' => 'Abril',
                                '5' => 'Mayo', '6' => 'Junio', '7' => 'Julio', '8' => 'Agosto',
                                '9' => 'Setiembre', '10' => 'Octubre', '11' => 'Noviembre', '12' => 'Diciembre',
                            ])
                            ->native(false)
                            ->visible(fn (Get $get): bool => $get('averageMode') === 'month'),
                        Select::make('calculationMethod')
                            ->label('Método de cálculo')
                            ->options([
                                'simple' => 'Promedio simple',
                                'weighted' => 'Promedio ponderado por recencia',
                            ])
                            ->hintIcon('heroicon-m-information-circle', 'Ponderado: da mayor peso a los días recientes. Simple: todos los días pesan igual.')
                            ->required()
                            ->native(false),
                        Select::make('recentWeeks')
                            ->label('Cobertura para día de semana')
                            ->options([
                                'all' => 'Todo el histórico',
                                '4' => 'Últimas 4 semanas',
                                '8' => 'Últimas 8 semanas',
                                '12' => 'Últimas 12 semanas',
                            ])
                            ->hintIcon('heroicon-m-information-circle', 'Limita el cálculo a las semanas más recientes del día seleccionado.')
                            ->native(false)
                            ->visible(fn (Get $get): bool => $get('averageMode') === 'weekday'),
                        Select::make('demandWindow')
                            ->label('Ventana de demanda')
                            ->options([
                                'full_day' => 'Día completo',
                                'before_restock' => 'Antes de reposición (09:30–14:00)',
                                'after_restock' => 'Después de reposición (14:00–cierre)',
                            ])
                            ->hintIcon('heroicon-m-information-circle', 'Antes de reposición usa ventas de 09:30 a 14:00; después usa ventas desde las 14:00.')
                            ->required()
                            ->native(false),
                        Toggle::make('showStockoutWarning')
                            ->label('Mostrar alertas de posible quiebre')
                            ->hintIcon('heroicon-m-information-circle', 'Solo alerta. No excluye ventas ni altera el promedio.')
                            ->default(true),
                        Select::make('selectedLocals')
                            ->label('Locales de la tabla')
                            ->options($this->localSelectOptions())
                            ->multiple()
                            ->native(false)
                            ->searchable()
                            ->optionsLimit(10)
                            ->placeholder('Selecciona locales o Todos'),
                        Select::make('selectedProducts')
                            ->label('Productos')
                            ->options($this->standardProductOptions())
                            ->multiple()
                            ->native(false)
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => $this->productSearchResults($search))
                            ->getOptionLabelsUsing(fn (array $values): array => $this->productLabels($values))
                            ->optionsLimit(10)
                            ->columnSpan(['xl' => 2])
                            ->hintIcon('heroicon-m-information-circle', 'El catálogo estándar se muestra como una sola selección. Puedes buscar y añadir productos específicos.')
                            ->placeholder('Busca y selecciona productos'),
                    ]),
            ])
            ->statePath('data');
    }

    public function syncDateRange(string $start, string $end, string $preset = 'custom'): void
    {
        if ($this->usesHistoricalCoverage()) {
            return;
        }

        $this->data['dateStart'] = $start;
        $this->data['dateEnd'] = $end;
        $this->activeDatePreset = $preset;
    }

    public function usesHistoricalCoverage(): bool
    {
        return ($this->data['averageMode'] ?? 'daily') !== 'daily';
    }

    /** @return array{0: string, 1: string} */
    public function dateRangeForDisplay(): array
    {
        if ($this->usesHistoricalCoverage()) {
            [$start, $end] = $this->historicalCoverageRange();

            return [$start->toDateString(), $end->toDateString()];
        }

        return [
            $this->data['dateStart'] ?? now()->toDateString(),
            $this->data['dateEnd'] ?? now()->toDateString(),
        ];
    }

    public function search(): void
    {
        $this->refreshSummary();
        $this->resetPage();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->matrixQuery())
            ->heading('Promedio por producto y local')
            ->columns([
                TextColumn::make('cod_interno')->label('Código')->searchable(),
                TextColumn::make('item_nombre')
                    ->label('Producto')
                    ->state(fn (KardexMovimiento $record): string => $this->standardProductName((string) $record->item_id, (string) $record->item_nombre))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('promedio_total')
                    ->label('Promedio total')
                    ->state(fn (KardexMovimiento $record): string => number_format((float) $record->promedio_total, 0))
                    ->alignEnd()
                    ->color('primary'),
                TextColumn::make('unidad')->label('Unidad')->badge(),
                ...$this->matrixLocalColumns(),
            ])
            ->defaultSort('promedio_total', 'desc')
            ->defaultKeySort(false)
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('No existen ventas para este cálculo.');
    }

    protected function refreshSummary(): void
    {
        $query = $this->salesQuery();
        $denominator = $this->denominator();
        $total = $this->weightedSalesTotal($query);
        $stockoutDays = $this->stockoutDays($query);

        $this->summary = [
            'denominator' => $denominator,
            'promedio_total' => $denominator > 0 ? (float) $total / $denominator : 0,
            'productos' => (clone $query)->distinct('item_id')->count('item_id'),
            'locales' => (clone $query)->distinct('local_id')->count('local_id'),
            'stockout_days' => $stockoutDays,
            'description' => $this->descriptionForMode($denominator).(blank($this->data['selectedLocals'] ?? [])
                ? ' Se muestran los 5 locales con mayor venta; selecciona locales para cambiar la tabla.'
                : ''),
        ];
    }

    protected function matrixQuery(): Builder
    {
        $denominator = max(1, $this->denominator());
        [$weightSql, $weightBindings] = $this->weightSql();
        $query = $this->salesQuery()
            ->selectRaw("MIN(id) AS id, MAX(cod_interno) AS cod_interno, item_id, MAX(item_nombre) AS item_nombre, MAX(unidad_medida) AS unidad, COALESCE(SUM(salida * ({$weightSql})), 0) / ? AS promedio_total", [...$weightBindings, $denominator])
            ->groupBy('item_id');

        foreach ($this->matrixLocalIds() as $index => $localId) {
            $query->selectRaw(
                sprintf('COALESCE(SUM(CASE WHEN local_id = ? THEN salida * (%s) ELSE 0 END), 0) / ? AS promedio_local_%d', $weightSql, $index),
                [$localId, ...$weightBindings, $denominator],
            );
        }

        return $query->orderByDesc('promedio_total');
    }

    protected function salesQuery(): Builder
    {
        [$start, $end] = $this->normalizedRange();
        $selectedLocals = $this->selectedLocalIds();
        $selectedProducts = $this->selectedProductIds();
        $mode = $this->data['averageMode'] ?? 'daily';

        $query = KardexMovimiento::query()
            ->where('motivo', self::MOTIVO_VENTA)
            ->where('almacen', self::ALMACEN_PRINCIPAL)
            ->where('salida', '>', 0)
            ->where('unidad_medida', $this->data['unidadMedida'] ?? 'UNIDAD')
            ->whereDate('fecha', '>=', $start)
            ->whereDate('fecha', '<=', $end)
            ->when(filled($selectedLocals), fn (Builder $query): Builder => $query->whereIn('local_id', $selectedLocals))
            ->when(filled($selectedProducts), fn (Builder $query): Builder => $query->whereIn('item_id', $selectedProducts));

        if (auth()->user()?->isRestrictedToLocals() && blank($selectedLocals)) {
            $query->whereIn('local_id', array_keys($this->localOptions));
        }

        if ($mode === 'weekday') {
            $query->whereRaw('EXTRACT(DOW FROM fecha) = ?', [(int) ($this->data['weekday'] ?? 1)]);
        }

        if ($mode === 'month') {
            $query->whereRaw('EXTRACT(MONTH FROM fecha) = ?', [(int) ($this->data['month'] ?? 1)]);
        }

        $window = $this->data['demandWindow'] ?? 'full_day';
        if ($window === 'before_restock') {
            $query->whereTime('hora', '>=', '09:30:00')->whereTime('hora', '<', '14:00:00');
        } elseif ($window === 'after_restock') {
            $query->whereTime('hora', '>=', '14:00:00');
        }

        return $query;
    }

    /** @return array<int, TextColumn> */
    protected function matrixLocalColumns(): array
    {
        return collect($this->matrixLocalIds())
            ->map(function ($localId, int $index): TextColumn {
                $alias = "promedio_local_{$index}";

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
        return $this->salesQuery()
            ->selectRaw('local_id, COALESCE(SUM(salida), 0) AS ventas')
            ->groupBy('local_id')
            ->orderByDesc('ventas')
            ->limit(5)
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

    protected function denominator(): float
    {
        return $this->comparisonDates()
            ->sum(fn (Carbon $date): float => $this->dateWeight($date));
    }

    /** @return array{0: string, 1: array<int, mixed>} */
    protected function weightSql(): array
    {
        if (($this->data['calculationMethod'] ?? 'weighted') !== 'weighted') {
            return ['1', []];
        }

        [$start, $end] = $this->normalizedRange();
        $span = max(1, $start->diffInDays($end));

        // Peso lineal 1.00 para el día más antiguo y hasta 2.00 para el más
        // reciente. El denominador se calcula con el mismo peso incluyendo
        // días sin venta.
        return ['1 + ((fecha - ?::date)::numeric / ?)', [$start->toDateString(), $span]];
    }

    protected function dateWeight(Carbon $date): float
    {
        if (($this->data['calculationMethod'] ?? 'weighted') !== 'weighted') {
            return 1.0;
        }

        [$start, $end] = $this->normalizedRange();
        $span = max(1, $start->diffInDays($end));

        return 1 + ($start->diffInDays($date) / $span);
    }

    protected function weightedSalesTotal(Builder $query): float
    {
        [$weightSql, $bindings] = $this->weightSql();

        return (float) ((clone $query)
            ->selectRaw("COALESCE(SUM(salida * ({$weightSql})), 0) AS total", $bindings)
            ->value('total') ?? 0);
    }

    protected function stockoutDays(Builder $query): int
    {
        if (! ($this->data['showStockoutWarning'] ?? true)) {
            return 0;
        }

        // Es una señal de revisión, no un filtro: eliminar esos días sin un
        // denominador por producto/local subestimaría el promedio. Se cuenta
        // un posible quiebre cuando una venta deja el stock registrado en 0.
        return (int) ((clone $query)
            ->where('stock', '<=', 0)
            ->selectRaw("COUNT(DISTINCT CONCAT(local_id, '|', item_id, '|', fecha)) AS total")
            ->value('total') ?? 0);
    }

    /** @return \Illuminate\Support\Collection<int, Carbon> */
    protected function comparisonDates(): \Illuminate\Support\Collection
    {
        [$start, $end] = $this->normalizedRange();
        $mode = $this->data['averageMode'] ?? 'daily';

        return collect(CarbonPeriod::create($start, $end))
            ->filter(function (Carbon $date) use ($mode): bool {
                if ($mode === 'daily') {
                    return true;
                }

                if ($mode === 'weekday') {
                    return $date->dayOfWeek === (int) ($this->data['weekday'] ?? 1);
                }

                return $mode === 'month' && $date->month === (int) ($this->data['month'] ?? 1);
            });
    }

    /** @return array{0: Carbon, 1: Carbon} */
    protected function normalizedRange(): array
    {
        if ($this->usesHistoricalCoverage()) {
            [$start, $end] = $this->historicalCoverageRange();
        } else {
            $start = Carbon::parse($this->data['dateStart'] ?? now()->startOfYear()->toDateString())->startOfDay();
            $end = Carbon::parse($this->data['dateEnd'] ?? now()->toDateString())->startOfDay();
        }

        [$start, $end] = $end->lessThan($start) ? [$end, $start] : [$start, $end];

        // Para lunes, domingos u otro día comparable, la cobertura elegida
        // se restringe a las últimas N semanas sin alterar el filtro de día.
        if (($this->data['averageMode'] ?? 'daily') === 'weekday') {
            $weeks = $this->data['recentWeeks'] ?? '8';
            if ($weeks !== 'all' && ctype_digit((string) $weeks)) {
                $start = $start->max($end->copy()->subWeeks((int) $weeks)->addDay());
            }
        }

        return [$start, $end];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    protected function historicalCoverageRange(): array
    {
        $query = KardexMovimiento::query()
            ->where('motivo', self::MOTIVO_VENTA)
            ->where('almacen', self::ALMACEN_PRINCIPAL)
            ->where('salida', '>', 0);

        if (auth()->user()?->isRestrictedToLocals()) {
            $query->whereIn('local_id', array_keys($this->localOptions));
        }

        $unidad = $this->data['unidadMedida'] ?? null;
        if (filled($unidad)) {
            $query->where('unidad_medida', $unidad);
        }

        $localIds = $this->selectedLocalIds();
        if ($localIds !== []) {
            $query->whereIn('local_id', $localIds);
        }

        $productIds = $this->selectedProductIds();
        if ($productIds !== []) {
            $query->whereIn('item_id', $productIds);
        }

        $start = $query->min('fecha');
        $end = $query->max('fecha');

        return [
            $start ? Carbon::parse($start)->startOfDay() : now()->startOfDay(),
            $end ? Carbon::parse($end)->startOfDay() : now()->startOfDay(),
        ];
    }

    protected function coverageDescription(): string
    {
        return match ((string) ($this->data['recentWeeks'] ?? '8')) {
            '4' => 'las últimas 4 semanas',
            '8' => 'las últimas 8 semanas',
            '12' => 'las últimas 12 semanas',
            default => 'todo el histórico disponible',
        };
    }

    protected function calculationMethodLabel(): string
    {
        return ($this->data['calculationMethod'] ?? 'weighted') === 'weighted'
            ? 'Promedio ponderado por recencia'
            : 'Promedio simple';
    }

    protected function demandWindowLabel(): string
    {
        return match ($this->data['demandWindow'] ?? 'full_day') {
            'before_restock' => 'Demanda antes de reposición (09:30–14:00)',
            'after_restock' => 'Demanda después de reposición (14:00–cierre)',
            default => 'Demanda de día completo',
        };
    }

    protected function descriptionForMode(float $denominator): string
    {
        $mode = $this->data['averageMode'] ?? 'daily';
        $prefix = $this->calculationMethodLabel().' · '.$this->demandWindowLabel().'. ';

        if ($mode === 'weekday') {
            $daysLabel = ($this->data['calculationMethod'] ?? 'weighted') === 'weighted' ? 'día(s) ponderados' : 'día(s) comparables';

            return $prefix."Promedio de {$this->selectedWeekdayLabel()} con {$this->coverageDescription()}: ".number_format($denominator, 1)." {$daysLabel}. Los días sin venta cuentan como cero.";
        }

        if ($mode === 'month') {
            return $prefix."Promedio por ".number_format($denominator, 1)." día(s) del mes dentro de la cobertura histórica. Los días sin venta cuentan como cero.";
        }

        return $prefix."Promedio por ".number_format($denominator, 1)." día(s) del rango. Los días sin venta cuentan como cero.";
    }

    public function averageTotalLabel(): string
    {
        if (($this->data['averageMode'] ?? 'daily') === 'weekday') {
            return 'Promedio total · '.$this->selectedWeekdayLabel();
        }

        if (($this->data['averageMode'] ?? 'daily') === 'month') {
            return 'Promedio total · mes seleccionado';
        }

        return 'Promedio diario total';
    }

    protected function selectedWeekdayLabel(): string
    {
        return [
            1 => 'lunes',
            2 => 'martes',
            3 => 'miércoles',
            4 => 'jueves',
            5 => 'viernes',
            6 => 'sábados',
            0 => 'domingos',
        ][(int) ($this->data['weekday'] ?? 1)] ?? 'lunes';
    }

    /** @return array<string, string> */
    public function productSearchResults(string $search): array
    {
        $search = trim($search);

        return $this->productBaseQuery()
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
            ->limit(20)
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

        $labels = [];
        if (in_array(self::STANDARD_PRODUCTS_OPTION, $ids, true)) {
            $labels[self::STANDARD_PRODUCTS_OPTION] = 'Catálogo estándar ('.count(self::STANDARD_PRODUCTS).')';
        }

        $ids = array_values(array_filter($ids, fn ($value): bool => $value !== self::STANDARD_PRODUCTS_OPTION));

        $standard = collect($ids)
            ->filter(fn ($itemId): bool => isset(self::STANDARD_PRODUCTS[(string) $itemId]))
            ->mapWithKeys(fn ($itemId): array => [
                (string) $itemId => self::STANDARD_PRODUCTS[(string) $itemId]['code'].' · '.self::STANDARD_PRODUCTS[(string) $itemId]['name'],
            ]);
        $unknownIds = array_values(array_filter(
            $ids,
            fn ($itemId): bool => ! isset(self::STANDARD_PRODUCTS[(string) $itemId]),
        ));

        if ($unknownIds === []) {
            return $labels + $standard->all();
        }

        return $labels + $standard->merge(KardexMovimiento::query()
            ->whereIn('item_id', $unknownIds)
            ->selectRaw('item_id, MAX(cod_interno) AS cod_interno, MAX(item_nombre) AS item_nombre')
            ->groupBy('item_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [(string) $row->item_id => trim("{$row->cod_interno} · {$row->item_nombre}", " ·")])
            ->all())->all();
    }

    /** @return array<string, string> */
    protected function standardProductOptions(): array
    {
        return [self::STANDARD_PRODUCTS_OPTION => 'Catálogo estándar ('.count(self::STANDARD_PRODUCTS).')'] + collect(self::STANDARD_PRODUCTS)
            ->mapWithKeys(fn (array $product, string $itemId): array => [
                $itemId => $product['code'].' · '.$product['name'],
            ])
            ->all();
    }

    protected function standardProductName(string $itemId, string $fallback): string
    {
        return self::STANDARD_PRODUCTS[$itemId]['name'] ?? $fallback;
    }

    protected function productBaseQuery(): Builder
    {
        [$start, $end] = $this->normalizedRange();
        $selectedLocals = $this->selectedLocalIds();
        $mode = $this->data['averageMode'] ?? 'daily';

        $query = KardexMovimiento::query()
            ->where('motivo', self::MOTIVO_VENTA)
            ->where('almacen', self::ALMACEN_PRINCIPAL)
            ->where('salida', '>', 0)
            ->where('unidad_medida', $this->data['unidadMedida'] ?? 'UNIDAD')
            ->whereDate('fecha', '>=', $start)
            ->whereDate('fecha', '<=', $end)
            ->when(filled($selectedLocals), fn (Builder $query): Builder => $query->whereIn('local_id', $selectedLocals));

        if (auth()->user()?->isRestrictedToLocals() && blank($selectedLocals)) {
            $query->whereIn('local_id', array_keys($this->localOptions));
        }

        if ($mode === 'weekday') {
            $query->whereRaw('EXTRACT(DOW FROM fecha) = ?', [(int) ($this->data['weekday'] ?? 1)]);
        }

        if ($mode === 'month') {
            $query->whereRaw('EXTRACT(MONTH FROM fecha) = ?', [(int) ($this->data['month'] ?? 1)]);
        }

        return $query;
    }

    /** @return array<int, string|int> */
    protected function selectedProductIds(): array
    {
        $values = array_values(array_filter((array) ($this->data['selectedProducts'] ?? []), fn ($value): bool => filled($value)));

        if (in_array(self::STANDARD_PRODUCTS_OPTION, $values, true)) {
            $values = [...array_keys(self::STANDARD_PRODUCTS), ...$values];
        }

        return array_values(array_unique(array_filter(
            $values,
            fn ($value): bool => $value !== self::STANDARD_PRODUCTS_OPTION,
        )));
    }

    protected function compactLocalLabel(string $name): string
    {
        return str($name)->replaceStart('DIM SUM ', '')->toString();
    }
}
