<?php

namespace App\Filament\Pages\Kardex;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Models\KardexMovimiento;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options as DompdfOptions;
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
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConsolidadoVentas extends Page implements HasTable
{
    use InteractsWithTable;
    use ScopesLocalsToUser;

    private const MOTIVO_VENTA = 'SALIDA, POR VENTA.';

    private const ALMACEN_PRINCIPAL = 'Almacen Principal';

    private const UNIDAD_MEDIDA = 'UNIDAD';

    private const ALL_LOCALES_OPTION = '__all_locales__';

    private const MAX_FECHAS_COMPARAR = 6;

    /**
     * Catálogo fijo pedido por el usuario -- el consolidado siempre lista
     * estos productos, en este orden exacto, tengan o no venta ese día
     * (fila en cero, no desaparece). item_id confirmado contra la data real
     * de kardex_movimientos (mismo nombre e id que ya usa el catálogo
     * estándar de Promedios de venta, más las 6 bebidas que no estaban ahí).
     *
     * @var array<int, array{item_id: int, code: string, name: string}>
     */
    private const CATALOGO = [
        ['item_id' => 153, 'code' => 'SM001', 'name' => 'SIU MAI TRADICIONAL'],
        ['item_id' => 106, 'code' => 'SM002', 'name' => 'SIU MAI ESPECIAL'],
        ['item_id' => 157, 'code' => 'SM003', 'name' => 'SIU MAI DE POLLO'],
        ['item_id' => 147, 'code' => 'WK001', 'name' => 'WO TI KAO'],
        ['item_id' => 137, 'code' => 'MP001', 'name' => 'MIN PAO DE POLLO'],
        ['item_id' => 118, 'code' => 'MP002', 'name' => 'MIN PAO DE CERDO'],
        ['item_id' => 159, 'code' => 'MP003', 'name' => 'MIN PAO DULCE'],
        ['item_id' => 138, 'code' => 'MP004', 'name' => 'MIN PAO MIXTO'],
        ['item_id' => 156, 'code' => 'ER001', 'name' => 'ENROLLADO PRIMAVERA'],
        ['item_id' => 105, 'code' => 'AA003', 'name' => 'ALAS ASADAS'],
        ['item_id' => 144, 'code' => 'AB001', 'name' => 'ALAS BROSTER'],
        ['item_id' => 158, 'code' => 'KP001', 'name' => 'KAI PI'],
        ['item_id' => 155, 'code' => 'WT001', 'name' => 'WANTAN'],
        ['item_id' => 154, 'code' => 'SK001', 'name' => 'SIU KAO'],
        ['item_id' => 143, 'code' => 'TP001', 'name' => 'TAYPAO'],
        ['item_id' => 161, 'code' => 'CS001', 'name' => 'CHA SIU - 250 G'],
        ['item_id' => 160, 'code' => 'CH001', 'name' => 'CHAUFA - 260 G'],
        ['item_id' => 58, 'code' => 'IC001', 'name' => 'Inca Kola - 300 ML'],
        ['item_id' => 72, 'code' => 'IC002', 'name' => 'Inca Kola - 600 ML'],
        ['item_id' => 59, 'code' => 'CC001', 'name' => 'Coca Cola - 300 ML'],
        ['item_id' => 71, 'code' => 'CC002', 'name' => 'Coca Cola - 600 ML'],
        ['item_id' => 74, 'code' => 'CO002', 'name' => 'Chicha - 300 ML'],
        ['item_id' => 78, 'code' => 'ASG002', 'name' => 'Agua - SAN MATEO 600 ML'],
    ];

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationLabel = 'Consolidado de ventas';

    protected static ?string $title = 'Consolidado de ventas';

    protected static string|\UnitEnum|null $navigationGroup = 'Kardex';

    protected static ?int $navigationSort = 35;

    protected static ?string $slug = 'kardex/consolidado-ventas';

    protected string $view = 'filament.pages.kardex.consolidado-ventas';

    /** @var array<string, string> */
    public array $localOptions = [];

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
            'localesComparar' => [],
        ]);

        $this->refreshSummary();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Toggle::make('compararFechas')
                    ->label('Comparar días específicos')
                    ->hintIcon('heroicon-m-information-circle', 'Cada columna combina local y fecha -- para ver, por ejemplo, cuánto vendió el Local 2 el día 29 contra el día 30.')
                    ->live(),
                Grid::make(['default' => 1, 'md' => 3])
                    ->visible(fn (Get $get): bool => ! $get('compararFechas'))
                    ->schema([
                        DatePicker::make('fecha')
                            ->label('Fecha')
                            ->required()
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                        Select::make('selectedLocals')
                            ->label('Locales')
                            ->options($this->localSelectOptions())
                            ->multiple()
                            ->native(false)
                            ->searchable()
                            ->optionsLimit(10)
                            ->placeholder('Todos los locales')
                            ->columnSpan(2),
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
                        Select::make('localesComparar')
                            ->label('Locales')
                            ->options($this->localOptions)
                            ->multiple()
                            ->native(false)
                            ->searchable()
                            ->optionsLimit(10)
                            ->placeholder('Todos los locales')
                            ->hintIcon('heroicon-m-information-circle', 'Sin selección se muestran todos los locales, cada uno con su propia columna por fecha.'),
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
                ->records(fn (int $page, int $recordsPerPage): LengthAwarePaginator => $this->paginate($this->buildComparativoRows(), $page, $recordsPerPage))
                ->heading('Unidades vendidas por producto, local y fecha')
                ->columns([
                    TextColumn::make('cod_interno')->label('Código'),
                    TextColumn::make('item_nombre')->label('Producto')->wrap(),
                    TextColumn::make('total')->label('Total')->numeric(0)->alignEnd()->color('primary'),
                    ...$this->comparativoColumns(),
                ])
                ->paginated([25, 50])
                ->defaultPaginationPageOption(25)
                ->emptyStateHeading('No hay ventas registradas para los locales y fechas elegidos.');
        }

        return $table
            ->records(fn (int $page, int $recordsPerPage): LengthAwarePaginator => $this->paginate($this->buildMatrixRows(), $page, $recordsPerPage))
            ->heading('Unidades vendidas por producto y local')
            ->columns([
                TextColumn::make('cod_interno')->label('Código'),
                TextColumn::make('item_nombre')->label('Producto')->wrap(),
                TextColumn::make('total')->label('Total')->numeric(0)->alignEnd()->color('primary'),
                ...$this->matrixLocalColumns(),
            ])
            ->paginated([25, 50])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('No hay ventas registradas para esta fecha.');
    }

    protected function paginate(Collection $rows, int $page, int $perPage): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'pageName' => 'consolidadoVentasPage'],
        );
    }

    protected function refreshSummary(): void
    {
        if ($this->comparando()) {
            $fechas = $this->fechasComparar();
            $localIds = $this->comparativoLocalIds();
            $query = $this->ventasBaseQuery()
                ->whereIn('item_id', self::catalogoItemIds())
                ->whereIn('fecha', $fechas)
                ->whereIn('local_id', $localIds);

            $this->summary = [
                'total_unidades' => (float) (clone $query)->sum('salida'),
                'productos' => (clone $query)->distinct('item_id')->count('item_id'),
                'fechas' => count($fechas),
                'locales' => count($localIds),
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

    /** @return Collection<int, array<string, mixed>> */
    protected function buildMatrixRows(): Collection
    {
        $localIds = $this->matrixLocalIds();

        $sums = $this->ventasQuery()
            ->whereIn('item_id', self::catalogoItemIds())
            ->selectRaw('item_id, local_id, COALESCE(SUM(salida), 0) AS total')
            ->groupBy('item_id', 'local_id')
            ->get()
            ->groupBy('item_id');

        return collect(self::CATALOGO)->map(function (array $producto) use ($sums, $localIds): array {
            $porLocal = ($sums->get($producto['item_id']) ?? collect())->pluck('total', 'local_id');

            $row = [
                'cod_interno' => $producto['code'],
                'item_nombre' => $producto['name'],
                'total' => 0.0,
            ];

            foreach ($localIds as $index => $localId) {
                $valor = (float) ($porLocal[$localId] ?? 0);
                $row["local_{$index}"] = $valor;
                $row['total'] += $valor;
            }

            return $row;
        });
    }

    /**
     * Una columna por combinación local x fecha (agrupadas por local, para
     * poder leer "local 2: día 29 vs día 30" en columnas contiguas) -- pedido
     * explícito del usuario: la comparación de fechas no debe mezclar todos
     * los locales en un solo número, tiene que discriminar por local.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function buildComparativoRows(): Collection
    {
        $fechas = $this->fechasComparar();
        $localIds = $this->comparativoLocalIds();

        $sums = $this->ventasBaseQuery()
            ->whereIn('item_id', self::catalogoItemIds())
            ->whereIn('fecha', $fechas)
            ->whereIn('local_id', $localIds)
            ->selectRaw('item_id, local_id, fecha, COALESCE(SUM(salida), 0) AS total')
            ->groupBy('item_id', 'local_id', 'fecha')
            ->get()
            ->groupBy('item_id');

        return collect(self::CATALOGO)->map(function (array $producto) use ($sums, $fechas, $localIds): array {
            $porLocalYFecha = ($sums->get($producto['item_id']) ?? collect())
                ->mapWithKeys(fn ($row): array => [$row->local_id.'|'.Carbon::parse($row->fecha)->toDateString() => $row->total]);

            $row = [
                'cod_interno' => $producto['code'],
                'item_nombre' => $producto['name'],
                'total' => 0.0,
            ];

            foreach ($localIds as $li => $localId) {
                foreach ($fechas as $fi => $fecha) {
                    $valor = (float) ($porLocalYFecha[$localId.'|'.$fecha] ?? 0);
                    $row["local_{$li}_fecha_{$fi}"] = $valor;
                    $row['total'] += $valor;
                }
            }

            return $row;
        });
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
            ->where('unidad_medida', self::UNIDAD_MEDIDA);
    }

    /** @return array<int, int> */
    protected static function catalogoItemIds(): array
    {
        return array_column(self::CATALOGO, 'item_id');
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

    /** @return array<int, string|int> */
    protected function comparativoLocalIds(): array
    {
        $values = array_values(array_filter((array) ($this->data['localesComparar'] ?? []), fn ($value): bool => filled($value)));

        $ids = filled($values) ? $this->restrictLocalIdsToUser($values) : array_keys($this->localOptions);

        return $ids !== [] ? $ids : array_keys($this->localOptions);
    }

    /** @return array<int, TextColumn> */
    protected function comparativoColumns(): array
    {
        $fechas = $this->fechasComparar();
        $columns = [];

        foreach ($this->comparativoLocalIds() as $li => $localId) {
            foreach ($fechas as $fi => $fecha) {
                $label = $this->compactLocalLabel($this->localOptions[$localId] ?? "Local {$localId}").' · '.Carbon::parse($fecha)->translatedFormat('d M');
                $columns[] = TextColumn::make("local_{$li}_fecha_{$fi}")
                    ->label($label)
                    ->numeric(0)
                    ->alignEnd();
            }
        }

        return $columns;
    }

    /** @return array<int, TextColumn> */
    protected function matrixLocalColumns(): array
    {
        return collect($this->matrixLocalIds())
            ->map(fn ($localId, int $index): TextColumn => TextColumn::make("local_{$index}")
                ->label($this->compactLocalLabel($this->localOptions[$localId] ?? "Local {$localId}"))
                ->numeric(0)
                ->alignEnd())
            ->all();
    }

    /**
     * Sin selección explícita, se muestran TODOS los locales del usuario
     * como columna -- el catálogo ahora es fijo y corto (23 filas), así que
     * una columna por local es legible (mismo criterio que ya usan las
     * matrices de Requerimientos/Guías internas).
     *
     * @return array<int, string|int>
     */
    protected function matrixLocalIds(): array
    {
        $selected = $this->selectedLocalIds();

        return filled($selected) ? array_values($selected) : array_keys($this->localOptions);
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

    /** @return array<int, array{alias: string, label: string}> */
    protected function exportColumnas(): array
    {
        if ($this->comparando()) {
            $fechas = $this->fechasComparar();
            $columnas = [];

            foreach ($this->comparativoLocalIds() as $li => $localId) {
                foreach ($fechas as $fi => $fecha) {
                    $columnas[] = [
                        'alias' => "local_{$li}_fecha_{$fi}",
                        'label' => ($this->localOptions[$localId] ?? "Local {$localId}").' -- '.Carbon::parse($fecha)->format('d/m/Y'),
                    ];
                }
            }

            return $columnas;
        }

        return collect($this->matrixLocalIds())
            ->map(fn ($localId, int $index): array => ['alias' => "local_{$index}", 'label' => $this->localOptions[$localId] ?? "Local {$localId}"])
            ->all();
    }

    /** @return Collection<int, array<string, mixed>> */
    protected function exportFilas(): Collection
    {
        return $this->comparando() ? $this->buildComparativoRows() : $this->buildMatrixRows();
    }

    protected function exportTitulo(): string
    {
        return $this->comparando() ? 'Consolidado de ventas -- comparativo de fechas' : 'Consolidado de ventas';
    }

    protected function exportSubtitulo(): string
    {
        if ($this->comparando()) {
            $fechas = collect($this->fechasComparar())->map(fn (string $f): string => Carbon::parse($f)->format('d/m/Y'))->implode(', ');

            return "Fechas: {$fechas} | Locales: ".count($this->comparativoLocalIds());
        }

        $locales = filled($this->selectedLocalIds()) ? 'seleccionados' : 'todos';

        return 'Fecha: '.$this->fechaLabel().' | Locales: '.$locales;
    }

    public function exportarExcel(): StreamedResponse
    {
        abort_unless(auth()->user()?->hasPermission('kardex.consolidado-ventas.exportar'), 403);

        $columnas = $this->exportColumnas();
        $filas = $this->exportFilas();
        $lastCol = count($columnas) + 3;
        $lastColLetter = Coordinate::stringFromColumnIndex($lastCol);
        $headerRow = 4;

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Consolidado de ventas');

        $sheet->mergeCells("A1:{$lastColLetter}1");
        $sheet->setCellValue('A1', $this->exportTitulo());
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->mergeCells("A2:{$lastColLetter}2");
        $sheet->setCellValue('A2', $this->exportSubtitulo().' | Generado: '.now()->format('d/m/Y H:i'));

        $sheet->setCellValue([1, $headerRow], 'Código');
        $sheet->setCellValue([2, $headerRow], 'Producto');
        foreach ($columnas as $index => $columna) {
            $sheet->setCellValue([$index + 3, $headerRow], $columna['label']);
        }
        $sheet->setCellValue([$lastCol, $headerRow], 'Total');

        $headerRange = "A{$headerRow}:{$lastColLetter}{$headerRow}";
        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5E7EB');
        $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $rowNumber = $headerRow + 1;
        foreach ($filas as $fila) {
            $sheet->setCellValue([1, $rowNumber], $fila['cod_interno']);
            $sheet->setCellValue([2, $rowNumber], $fila['item_nombre']);
            foreach ($columnas as $index => $columna) {
                $sheet->setCellValue([$index + 3, $rowNumber], (float) ($fila[$columna['alias']] ?? 0));
            }
            $sheet->setCellValue([$lastCol, $rowNumber], (float) $fila['total']);
            $rowNumber++;
        }

        $lastRow = max($headerRow, $rowNumber - 1);
        $sheet->getStyle("A{$headerRow}:{$lastColLetter}{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFD1D5DB'));
        if ($lastRow > $headerRow) {
            $sheet->getStyle('C'.($headerRow + 1).":{$lastColLetter}{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle("{$lastColLetter}".($headerRow + 1).":{$lastColLetter}{$lastRow}")->getFont()->setBold(true);
        }
        $sheet->getColumnDimension('A')->setWidth(12);
        $sheet->getColumnDimension('B')->setWidth(30);
        foreach (range(3, $lastCol) as $index) {
            $sheet->getColumnDimensionByColumn($index)->setWidth(13);
        }
        $sheet->freezePane('C'.($headerRow + 1));

        $writer = new Xlsx($spreadsheet);
        $filename = 'consolidado-ventas-'.now()->format('Y-m-d_His').'.xlsx';

        return response()->streamDownload(function () use ($writer, $spreadsheet): void {
            try {
                $writer->save('php://output');
            } finally {
                $spreadsheet->disconnectWorksheets();
            }
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function exportarPdf(): StreamedResponse
    {
        abort_unless(auth()->user()?->hasPermission('kardex.consolidado-ventas.exportar'), 403);

        $options = new DompdfOptions();
        $options->set('isRemoteEnabled', false);
        $pdf = new Dompdf($options);
        $pdf->setPaper('a4', 'landscape');
        $pdf->loadHtml(view('filament.pages.kardex.consolidado-ventas-pdf', [
            'titulo' => $this->exportTitulo(),
            'subtitulo' => $this->exportSubtitulo(),
            'columnas' => $this->exportColumnas(),
            'filas' => $this->exportFilas(),
        ])->render());
        $pdf->render();

        $filename = 'consolidado-ventas-'.now()->format('Y-m-d_His').'.pdf';

        // streamDownload() evita que Livewire intente meter el PDF binario en
        // el JSON de la respuesta AJAX del wire:click (mismo motivo que
        // exportarExcel(); ver Reporte de requerimientos, que ya usa este
        // mismo patrón).
        return response()->streamDownload(function () use ($pdf): void {
            echo $pdf->output();
        }, $filename, ['Content-Type' => 'application/pdf']);
    }
}
