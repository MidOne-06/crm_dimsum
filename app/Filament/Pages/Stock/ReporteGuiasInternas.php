<?php

namespace App\Filament\Pages\Stock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Models\GuiaInterna;
use App\Models\GuiaInternaDetalle;
use App\Services\GuiasInternasGatewayClient;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options as DompdfOptions;
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
use Illuminate\Support\Facades\Artisan;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * A diferencia de ReporteRequerimientos (que es de solo lectura), este
 * reporte SÍ sincroniza: al aplicar filtros, corre
 * guias-internas:sincronizar con el rango de fechas/locales/estado
 * elegidos -- igual que el botón "Aplicar filtros" del listado principal
 * de Guías internas -- para que la matriz refleje datos reales y
 * recientes de Restaurant antes de mostrarse y de poder exportarse.
 */
class ReporteGuiasInternas extends Page implements HasTable
{
    use InteractsWithTable;
    use ScopesLocalsToUser;

    private const ALL_LOCALES_OPTION = '__all_locales__';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-table-cells';
    protected static ?string $navigationLabel = 'Reporte de guías';
    protected static ?string $title = 'Reporte de guías internas';
    protected static string|\UnitEnum|null $navigationGroup = 'Guías internas';
    protected static ?int $navigationSort = 13;
    protected static ?string $slug = 'guias-internas/reporte';
    protected string $view = 'filament.pages.stock.reporte-guias-internas';

    /** @var array<string, string> */
    public array $localOptions = [];

    /** @var array<string, mixed> */
    public array $data = [];

    public string $activeDatePreset = 'month';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('guias-internas.reporte.view');
    }

    public function mount(): void
    {
        try {
            $this->localOptions = collect($this->scopeLocalsToUser($this->gateway()->locales()))
                ->mapWithKeys(fn (array $local): array => [(string) ($local['id'] ?? '') => (string) ($local['name'] ?? '')])
                ->filter(fn (string $name, string $id): bool => $id !== '' && $name !== '')
                ->all();
        } catch (Throwable) {
            // El reporte sigue disponible para consultar el historial local.
            $this->localOptions = $this->localOptionsFromHistory();
        }

        $this->form->fill([
            'fechaTipo' => 'emision',
            'estado' => '',
            'metric' => 'cantidad',
            // Igual que en Reporte de requerimientos: "todos" es una
            // intención explícita, distinta de "no filtrado" -- así la
            // matriz sabe qué columnas de local mostrar.
            'origenLocals' => [self::ALL_LOCALES_OPTION],
            'destinoLocals' => [self::ALL_LOCALES_OPTION],
            'selectedProducts' => [],
        ]);
        $ultimaFechaConDatos = GuiaInterna::query()->max('fecha_emision');
        $anclaFin = $ultimaFechaConDatos ? Carbon::parse($ultimaFechaConDatos)->min(now()) : now();
        $this->data['dateStart'] = $anclaFin->copy()->subDays(30)->toDateString();
        $this->data['dateEnd'] = $anclaFin->toDateString();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'md' => 2, 'xl' => 4])->schema([
                Select::make('fechaTipo')
                    ->label('Filtrar fecha por')
                    ->options(['emision' => 'Fecha de emisión', 'traslado' => 'Fecha de traslado'])
                    ->default('emision')->required()->native(false),
                Select::make('metric')
                    ->label('Cantidad a reportar')
                    ->options(['cantidad' => 'Cantidad', 'cantidad_salida' => 'Cantidad de salida', 'total' => 'Total (S/)'])
                    ->default('cantidad')->required()->native(false),
                Select::make('estado')
                    ->label('Estado')
                    ->options($this->statusOptions())
                    ->placeholder('Todos los estados')->native(),
                TextInput::make('codigo')
                    ->label('Código de guía')
                    ->maxLength(40),
                Select::make('origenLocals')
                    ->label('Local de origen')
                    ->options($this->localSelectOptions())
                    ->multiple()->searchable()->native(false)->optionsLimit(10)
                    ->placeholder('Todos los locales'),
                Select::make('destinoLocals')
                    ->label('Local de destino')
                    ->options($this->localSelectOptions())
                    ->multiple()->searchable()->native(false)->optionsLimit(10)
                    ->placeholder('Todos los locales'),
                Select::make('selectedProducts')
                    ->label('Productos')
                    ->multiple()->searchable()->native(false)->optionsLimit(20)
                    ->getSearchResultsUsing(fn (string $search): array => $this->productSearchResults($search))
                    ->getOptionLabelsUsing(fn (array $values): array => $this->productLabels($values))
                    ->placeholder('Todos los productos')
                    ->columnSpan(['xl' => 4]),
            ]),
        ])->statePath('data');
    }

    public function syncDateRange(string $start, string $end, string $preset = 'custom'): void
    {
        $start = Carbon::parse($start)->toDateString();
        $end = Carbon::parse($end)->toDateString();
        if ($start > $end) [$start, $end] = [$end, $start];

        $this->data['dateStart'] = $start;
        $this->data['dateEnd'] = $end;
        $this->activeDatePreset = $preset;
    }

    public function search(): void
    {
        $this->sincronizarCopiaDeFiltros();
        $this->resetPage();
        $this->cerrarFiltrosReporte();
    }

    public function abrirFiltrosReporte(): void
    {
        $this->dispatch('open-modal', id: 'filtros-reporte-guias-internas');
    }

    public function cerrarFiltrosReporte(): void
    {
        $this->dispatch('close-modal', id: 'filtros-reporte-guias-internas');
    }

    /**
     * Sincroniza la copia local (cabecera guias_internas + detalle
     * guia_interna_detalles) contra Restaurant, con el mismo rango de
     * fechas/locales/estado que el formulario de filtros. Un usuario sin
     * permiso de sincronizar sigue pudiendo ver y exportar lo que ya esté
     * guardado localmente -- solo se omite el refresco contra Restaurant.
     */
    private function sincronizarCopiaDeFiltros(): void
    {
        if (! auth()->user()?->hasPermission('guias-internas.sincronizar')) return;

        Artisan::call('guias-internas:sincronizar', [
            '--desde' => $this->dateStart(),
            '--hasta' => $this->dateEnd(),
            '--locales' => $this->selectedLocalIds('origenLocals'),
            '--estado' => filled($this->data['estado'] ?? null) ? (string) $this->data['estado'] : '-1',
        ]);
    }

    public function ultimaFechaConDatos(): ?string
    {
        $fecha = GuiaInterna::query()->max('fecha_emision');

        return $fecha ? Carbon::parse($fecha)->format('d/m/Y H:i') : null;
    }

    public function exportarExcel(): StreamedResponse
    {
        abort_unless(auth()->user()?->hasPermission('guias-internas.reporte.exportar'), 403);
        $locals = $this->matrixLocalNames();
        $rows = $this->matrixRows();
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Guías internas');

        $lastColumn = count($locals) + 4;
        $lastColumnLetter = Coordinate::stringFromColumnIndex($lastColumn);
        $headerRow = 5;

        $sheet->mergeCells("A1:{$lastColumnLetter}1");
        $sheet->setCellValue('A1', 'Reporte de guías internas');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->mergeCells("A2:{$lastColumnLetter}2");
        $sheet->setCellValue('A2', $this->exportFilterLabel());

        foreach (['Código', 'Producto', 'Unidad'] as $index => $heading) {
            $sheet->setCellValue([$index + 1, $headerRow], $heading);
        }
        foreach ($locals as $index => $local) {
            $sheet->setCellValue([$index + 4, $headerRow], $local);
        }
        $sheet->setCellValue([$lastColumn, $headerRow], 'TOTAL');

        $headerRange = "A{$headerRow}:{$lastColumnLetter}{$headerRow}";
        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5E7EB');
        $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        foreach (range(4, $lastColumn) as $columnIndex) {
            $sheet->getStyle(Coordinate::stringFromColumnIndex($columnIndex).$headerRow)->getAlignment()->setTextRotation(90);
        }
        $sheet->getRowDimension($headerRow)->setRowHeight(120);

        $rowNumber = $headerRow + 1;
        foreach ($rows as $row) {
            $sheet->setCellValue([1, $rowNumber], $row->codigo);
            $sheet->setCellValue([2, $rowNumber], $row->item);
            $sheet->setCellValue([3, $rowNumber], $row->unidad);
            foreach ($locals as $index => $_) {
                $sheet->setCellValue([$index + 4, $rowNumber], (float) ($row->{'local_'.$index} ?? 0));
            }
            $sheet->setCellValue([$lastColumn, $rowNumber], (float) $row->cantidad_total);
            $rowNumber++;
        }

        $lastRow = max($headerRow, $rowNumber - 1);
        $sheet->getStyle("A{$headerRow}:{$lastColumnLetter}{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFD1D5DB'));
        if ($lastRow > $headerRow) {
            $sheet->getStyle('D'.($headerRow + 1).":{$lastColumnLetter}{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle("{$lastColumnLetter}".($headerRow + 1).":{$lastColumnLetter}{$lastRow}")->getFont()->setBold(true);
        }
        $sheet->getColumnDimension('A')->setWidth(15);
        $sheet->getColumnDimension('B')->setWidth(38);
        $sheet->getColumnDimension('C')->setWidth(14);
        foreach (range(4, $lastColumn) as $index) $sheet->getColumnDimensionByColumn($index)->setWidth(14);
        $sheet->freezePane('D'.($headerRow + 1));

        $writer = new Xlsx($spreadsheet);
        $filename = 'reporte-guias-internas-'.now()->format('Y-m-d_His').'.xlsx';

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
        abort_unless(auth()->user()?->hasPermission('guias-internas.reporte.exportar'), 403);
        $options = new DompdfOptions();
        $options->set('isRemoteEnabled', false);
        $pdf = new Dompdf($options);
        $pdf->setPaper('a3', 'landscape');
        $pdf->loadHtml(view('filament.pages.stock.reporte-guias-internas-pdf', [
            'locals' => $this->matrixLocalNames(),
            'rows' => $this->matrixRows(),
            'filters' => $this->exportFilterLabel(),
        ])->render());
        $pdf->render();

        $filename = 'reporte-guias-internas-'.now()->format('Y-m-d_His').'.pdf';

        return response()->streamDownload(function () use ($pdf): void {
            echo $pdf->output();
        }, $filename, ['Content-Type' => 'application/pdf']);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->matrixQuery())
            ->heading('Matriz de guías internas')
            ->columns([
                TextColumn::make('codigo')->label('Código')->searchable()->toggleable(),
                TextColumn::make('item')->label('Producto')->searchable()->wrap(),
                TextColumn::make('unidad')->label('Unidad')->badge(),
                ...$this->matrixLocalColumns(),
                TextColumn::make('cantidad_total')->label('Total')->state(fn (GuiaInternaDetalle $record): string => number_format((float) $record->cantidad_total, 0))->alignEnd()->color('primary'),
            ])
            ->defaultSort('cantidad_total', 'desc')
            ->defaultKeySort(false)
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('Sin registros');
    }

    protected function matrixQuery(): Builder
    {
        $metric = $this->metricColumn();
        $dateColumn = $this->dateColumn();
        $locals = $this->matrixLocalNames();
        $products = $this->selectedProducts();
        $origen = $this->selectedLocalNames('origenLocals');
        $destino = $this->selectedLocalNames('destinoLocals');

        $query = GuiaInternaDetalle::query()
            ->join('guias_internas as guias', 'guias.id', '=', 'guia_interna_detalles.guia_interna_id')
            ->whereDate("guias.{$dateColumn}", '>=', $this->dateStart())
            ->whereDate("guias.{$dateColumn}", '<=', $this->dateEnd())
            ->when(filled($this->data['estado'] ?? null), fn (Builder $query): Builder => $query->where('guias.estado_codigo', $this->data['estado']))
            ->when(filled($this->data['codigo'] ?? null), fn (Builder $query): Builder => $query->where('guias.restaurant_id', 'like', '%'.$this->data['codigo'].'%'))
            ->when($origen !== [], fn (Builder $query): Builder => $query->whereIn('guias.local_origen', $origen))
            ->when($destino !== [], fn (Builder $query): Builder => $query->whereIn('guias.local_destino', $destino))
            ->when($products !== [], fn (Builder $query): Builder => $query->whereIn('guia_interna_detalles.item_codigo', $products))
            ->selectRaw("MIN(guia_interna_detalles.id) AS id, MAX(guia_interna_detalles.item_codigo) AS codigo, MAX(guia_interna_detalles.item) AS item, MAX(guia_interna_detalles.unidad) AS unidad, COALESCE(SUM(guia_interna_detalles.{$metric}), 0) AS cantidad_total")
            ->groupBy('guia_interna_detalles.item_codigo');

        foreach ($locals as $index => $localName) {
            $query->selectRaw("COALESCE(SUM(CASE WHEN guias.local_origen = ? THEN guia_interna_detalles.{$metric} ELSE 0 END), 0) AS local_{$index}", [$localName]);
        }

        return $query->orderByDesc('cantidad_total');
    }

    /** @return \Illuminate\Support\Collection<int, GuiaInternaDetalle> */
    protected function matrixRows(): \Illuminate\Support\Collection
    {
        return $this->matrixQuery()->get();
    }

    /** @return array<int, TextColumn> */
    protected function matrixLocalColumns(): array
    {
        return collect($this->matrixLocalNames())->map(function (string $name, int $index): TextColumn {
            $alias = "local_{$index}";
            return TextColumn::make($alias)
                ->label($this->compactLocalLabel($name))
                ->state(fn (GuiaInternaDetalle $record): string => number_format((float) ($record->{$alias} ?? 0), 0))
                ->alignEnd()
                ->toggleable();
        })->all();
    }

    /** @return array<int, string> */
    protected function matrixLocalNames(): array
    {
        $selected = $this->selectedLocalNames('origenLocals');
        if ($selected !== []) return $selected;

        return GuiaInterna::query()
            ->whereDate($this->dateColumn(), '>=', $this->dateStart())
            ->whereDate($this->dateColumn(), '<=', $this->dateEnd())
            ->whereNotNull('local_origen')->distinct()->orderBy('local_origen')->pluck('local_origen')->all();
    }

    /** @return array<string, string> */
    protected function localSelectOptions(): array
    {
        return [self::ALL_LOCALES_OPTION => 'Todos los locales'] + $this->localOptions;
    }

    /** @return array<string, string> */
    public function productSearchResults(string $search): array
    {
        $search = trim($search);
        if (mb_strlen($search) < 2) return [];

        return GuiaInternaDetalle::query()
            ->where(fn (Builder $query): Builder => $query->where('item_codigo', 'ilike', "%{$search}%")->orWhere('item', 'ilike', "%{$search}%"))
            ->selectRaw('item_codigo, MAX(item) AS item')->groupBy('item_codigo')->orderBy('item')->limit(50)->get()
            ->mapWithKeys(fn (GuiaInternaDetalle $item): array => [(string) $item->item_codigo => trim("{$item->item_codigo} · {$item->item}", ' ·')])->all();
    }

    /** @param array<int, mixed> $values @return array<string, string> */
    public function productLabels(array $values): array
    {
        return GuiaInternaDetalle::query()->whereIn('item_codigo', $values)
            ->selectRaw('item_codigo, MAX(item) AS item')->groupBy('item_codigo')->get()
            ->mapWithKeys(fn (GuiaInternaDetalle $item): array => [(string) $item->item_codigo => trim("{$item->item_codigo} · {$item->item}", ' ·')])->all();
    }

    /** @return array<string, string> */
    protected function statusOptions(): array
    {
        return GuiaInterna::query()
            ->whereNotNull('estado_codigo')
            ->distinct()
            ->orderBy('estado_codigo')
            ->pluck('estado_codigo')
            ->mapWithKeys(fn (string $estado): array => [$estado => $this->statusLabel($estado)])
            ->all();
    }

    private function statusLabel(string $estado): string
    {
        return ['0' => 'Anulada', '1' => 'Activa', '2' => 'Importada', '3' => 'Agrupada', '4' => 'Sin Facturar'][$estado] ?? $estado;
    }

    /** @return array<string, string> */
    protected function localOptionsFromHistory(): array
    {
        return GuiaInterna::query()->whereNotNull('local_origen')->distinct()->orderBy('local_origen')->pluck('local_origen', 'local_origen')->all();
    }

    /** @return array<int, string> */
    protected function selectedProducts(): array
    {
        return array_values(array_filter((array) ($this->data['selectedProducts'] ?? []), fn ($value): bool => filled($value)));
    }

    /** @return array<int, string> */
    protected function selectedLocalNames(string $field): array
    {
        $selected = array_values(array_filter((array) ($this->data[$field] ?? []), fn ($value): bool => filled($value)));

        if (in_array(self::ALL_LOCALES_OPTION, $selected, true)) {
            return array_values($this->localOptions);
        }

        $ids = $this->restrictLocalIdsToUser($selected);

        return array_values(array_intersect_key($this->localOptions, array_flip(array_map('strval', $ids))));
    }

    /**
     * A diferencia de selectedLocalNames() (que resuelve a nombres, para
     * filtrar la matriz local por guias.local_origen/local_destino), la
     * sincronización necesita los IDs reales de Restaurant tal como los
     * espera guias-internas:sincronizar. "Todos" se traduce a un arreglo
     * vacío -- sin restricción de locales -- en vez de listar todos los IDs.
     *
     * @return array<int, string>
     */
    protected function selectedLocalIds(string $field): array
    {
        $selected = array_values(array_filter((array) ($this->data[$field] ?? []), fn ($value): bool => filled($value)));

        if (in_array(self::ALL_LOCALES_OPTION, $selected, true)) {
            return [];
        }

        return $this->restrictLocalIdsToUser($selected);
    }

    protected function metricColumn(): string
    {
        return match ($this->data['metric'] ?? 'cantidad') {
            'cantidad_salida' => 'cantidad_salida', 'total' => 'total', default => 'cantidad',
        };
    }

    protected function dateColumn(): string
    {
        return ($this->data['fechaTipo'] ?? 'emision') === 'traslado' ? 'fecha_traslado' : 'fecha_emision';
    }

    protected function dateStart(): string { return Carbon::parse($this->data['dateStart'] ?? now()->startOfMonth())->toDateString(); }
    protected function dateEnd(): string { return Carbon::parse($this->data['dateEnd'] ?? now())->toDateString(); }
    protected function compactLocalLabel(string $name): string { return str_replace('DIM SUM ', '', $name); }

    protected function exportFilterLabel(): string
    {
        $dateLabel = ($this->data['fechaTipo'] ?? 'emision') === 'traslado' ? 'Traslado' : 'Emisión';
        $metric = ['cantidad' => 'Cantidad', 'cantidad_salida' => 'Cantidad de salida', 'total' => 'Total (S/)'][$this->data['metric'] ?? 'cantidad'];
        $origen = $this->selectedLocalNames('origenLocals');
        $destino = $this->selectedLocalNames('destinoLocals');
        $origenLabel = $origen === array_values($this->localOptions) ? 'Todos' : implode(', ', $origen);
        $destinoLabel = $destino === array_values($this->localOptions) ? 'Todos' : implode(', ', $destino);

        return "Fecha de {$dateLabel}: {$this->dateStart()} al {$this->dateEnd()} | Cantidad: {$metric} | Origen: {$origenLabel} | Destino: {$destinoLabel}";
    }

    private function gateway(): GuiasInternasGatewayClient { return app(GuiasInternasGatewayClient::class); }
}
