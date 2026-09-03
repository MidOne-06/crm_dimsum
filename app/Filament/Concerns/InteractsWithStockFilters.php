<?php

namespace App\Filament\Concerns;

use App\Services\StockGatewayClient;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View as ViewComponent;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Estado y lógica de filtros compartida por los submódulos de Stock Actual
 * (Consolidado y Stock), replicando 1:1 el comportamiento de D:\DS-TI\API-TI
 * (public/app.js) sobre el gateway Node que mantiene la sesión con Dim Sum.
 *
 * Los filtros principales (locales, estado, tipo, fechas) se declaran como un
 * Schema/Form real de Filament -para que la distribución (columnas, buscador,
 * selección masiva) y el tema claro/oscuro los resuelva Filament, no CSS a mano.
 */
trait InteractsWithStockFilters
{
    use ScopesLocalsToUser;

    public bool $gatewayUnavailable = false;

    public ?string $filtersError = null;

    /** @var array<int, array{id: string, name: string}> */
    public array $availableLocals = [];

    /** @var array<int, array{value: string, label: string}> */
    public array $estadoOptions = [];

    /** @var array<int, array{value: string, label: string}> */
    public array $tipoOptions = [];

    /** @var array<string, mixed> */
    public ?array $data = [];

    public string $itemSearch = '';

    /** @var array<int, array{id: string, type: string, subtype: ?string, name: string, code: string}> */
    public array $itemSuggestions = [];

    /** @var array<int, array{id: string, type: string, name: string}> */
    public array $selectedItems = [];

    public bool $hasSearched = false;

    public bool $isLoading = false;

    public ?string $resultError = null;

    /** @var array<int, array<string, mixed>> */
    public array $reportMasterRows = [];

    public int $cuadresIncluidos = 0;

    public int $cuadresEncontrados = 0;

    public int $paginasConsultadas = 0;

    public string $reportFilterLocal = '';

    public string $reportFilterAlmacen = '';

    public string $reportFilterItem = '';

    public string $reportFilterTipo = '';

    public int $reportPage = 1;

    public int $reportPageSize = 10;

    public string $activeDatePreset = 'today';

    public function mountInteractsWithStockFilters(): void
    {
        $today = now()->toDateString();

        try {
            $gateway = $this->gateway();
            $this->availableLocals = $this->scopeLocalsToUser($gateway->locals());
            $options = $gateway->filterOptions();
            $this->estadoOptions = $options['estados'] ?? [];
            $this->tipoOptions = $options['tipos'] ?? [];
        } catch (Throwable $exception) {
            $this->gatewayUnavailable = true;
            $this->filtersError = $this->friendlyGatewayError($exception);

            return;
        }

        $this->form->fill([
            'selectedLocals' => array_map(static fn (array $local): string => $local['id'], $this->availableLocals),
            'estado' => '1',
            'tipo' => '-1',
        ]);

        // El fill() del schema reemplaza el arreglo $data completo, así que las
        // fechas (que no son campos del schema, viven fuera de él) se fijan después.
        $this->data['fechaInicio'] = $today;
        $this->data['fechaFin'] = $today;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Locales')
                    ->collapsible()
                    ->collapsed()
                    ->compact()
                    ->schema([
                        CheckboxList::make('selectedLocals')
                            ->hiddenLabel()
                            ->options(fn (): array => collect($this->availableLocals)->pluck('name', 'id')->all())
                            ->columns(['default' => 1, 'sm' => 2, 'lg' => 3, 'xl' => 4])
                            ->bulkToggleable()
                            ->searchable()
                            ->gridDirection('row'),
                    ]),
                Grid::make(['default' => 1, 'md' => 2])
                    ->extraAttributes(['class' => 'crm-filter-select-grid'])
                    ->schema([
                        Select::make('estado')
                            ->label('Estado')
                            ->native(false)
                            ->options(fn (): array => collect($this->estadoOptions)->pluck('label', 'value')->all()),
                        Select::make('tipo')
                            ->label('Tipo de cuadre')
                            ->native(false)
                            ->options(fn (): array => collect($this->tipoOptions)->pluck('label', 'value')->all()),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * Estándar de modales de Filament nativo: reemplaza la sección "Filtros"
     * que antes vivía siempre visible arriba de la página por un botón que
     * abre un modal (Action::make()->schema()->action()), igual que el
     * resto de módulos ya convertidos. El rango de fechas y el buscador de
     * insumo/producto no son campos de Schema (son un widget Alpine y un
     * buscador con sugerencias en vivo, respectivamente) -- se embeben
     * dentro del mismo modal con un componente View, que renderiza sobre
     * el mismo Livewire de la página sin duplicar su estado.
     */
    protected function filtrosModalAction(): Action
    {
        return Action::make('filtros')
            ->label('Filtros')
            ->icon('heroicon-o-adjustments-horizontal')
            ->color('gray')
            ->modalHeading('Filtros')
            ->modalWidth('4xl')
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitActionLabel('Buscar')
            ->modalCancelActionLabel('Cancelar')
            ->fillForm(fn (): array => $this->data ?? [])
            ->schema([
                Section::make('Locales')
                    ->collapsible()
                    ->collapsed()
                    ->compact()
                    ->schema([
                        CheckboxList::make('selectedLocals')
                            ->hiddenLabel()
                            ->options(fn (): array => collect($this->availableLocals)->pluck('name', 'id')->all())
                            ->columns(['default' => 1, 'sm' => 2, 'lg' => 3, 'xl' => 4])
                            ->bulkToggleable()
                            ->searchable()
                            ->gridDirection('row'),
                    ]),
                Grid::make(['default' => 1, 'md' => 2])
                    ->schema([
                        Select::make('estado')
                            ->label('Estado')
                            ->native(false)
                            ->options(fn (): array => collect($this->estadoOptions)->pluck('label', 'value')->all()),
                        Select::make('tipo')
                            ->label('Tipo de cuadre')
                            ->native(false)
                            ->options(fn (): array => collect($this->tipoOptions)->pluck('label', 'value')->all()),
                    ]),
                ViewComponent::make('filament.pages.stock.partials.filtros-modal-extra')
                    ->viewData(fn (): array => [
                        'data' => $this->data ?? [],
                        'activeDatePreset' => $this->activeDatePreset,
                        'itemSearch' => $this->itemSearch,
                        'itemSuggestions' => $this->itemSuggestions,
                        'selectedItems' => $this->selectedItems,
                    ]),
            ])
            ->action(function (array $data): void {
                $this->data = array_merge($this->data ?? [], $data);
                $this->search();
            });
    }

    protected function gateway(): StockGatewayClient
    {
        return app(StockGatewayClient::class);
    }

    protected function friendlyGatewayError(Throwable $exception): string
    {
        Log::error('[Stock] '.$exception->getMessage(), ['exception' => $exception]);

        if (str_contains($exception->getMessage(), 'cURL error 7') || str_contains($exception->getMessage(), 'Connection refused')) {
            return 'No se pudo conectar con el servicio de Stock.';
        }

        return $exception->getMessage();
    }

    /**
     * Único punto de entrada server-side para el selector de rango de fechas:
     * el calendario doble-mes y los atajos (Hoy/Ayer/Últimos 7 días/...) se
     * calculan en Alpine (cliente) y solo se sincronizan al servidor al
     * presionar "Aplicar", para no ir y volver por cada clic en un día.
     */
    public function syncDateRange(string $start, string $end, string $preset = 'custom'): void
    {
        $this->data['fechaInicio'] = $start;
        $this->data['fechaFin'] = $end;
        $this->activeDatePreset = $preset;
    }

    public function updatedItemSearch(): void
    {
        $query = trim($this->itemSearch);

        if ($query === '') {
            $this->itemSuggestions = [];

            return;
        }

        try {
            $results = $this->gateway()->searchItems($query);
            $this->itemSuggestions = array_values(array_filter(
                $results,
                fn (array $item): bool => ! collect($this->selectedItems)->contains(
                    fn (array $selected): bool => $selected['id'] === $item['id'] && $selected['type'] === $item['type'],
                ),
            ));
        } catch (Throwable) {
            $this->itemSuggestions = [];
        }
    }

    public function addItem(string $id, string $type, string $name): void
    {
        if (count($this->selectedItems) >= 5) {
            $this->resultError = 'Solo puedes seleccionar hasta 5 insumos o productos.';

            return;
        }

        if (! collect($this->selectedItems)->contains(fn (array $selected): bool => $selected['id'] === $id && $selected['type'] === $type)) {
            $this->selectedItems[] = ['id' => $id, 'type' => $type, 'name' => $name];
        }

        $this->itemSearch = '';
        $this->itemSuggestions = [];
    }

    public function removeItem(int $index): void
    {
        unset($this->selectedItems[$index]);
        $this->selectedItems = array_values($this->selectedItems);
    }

    public function clearReportFilters(): void
    {
        $this->reportFilterLocal = '';
        $this->reportFilterAlmacen = '';
        $this->reportFilterItem = '';
        $this->reportFilterTipo = '';
        $this->reportPage = 1;
    }

    public function goToReportPage(int $delta): void
    {
        $this->reportPage = max(1, $this->reportPage + $delta);
    }

    public function updatedReportPageSize(): void
    {
        $this->reportPage = 1;
    }

    public function resetReportPage(): void
    {
        $this->reportPage = 1;
    }

    public function applyReportFilter(string $filter, string $value): void
    {
        $property = match ($filter) {
            'local' => 'reportFilterLocal',
            'almacen' => 'reportFilterAlmacen',
            'item' => 'reportFilterItem',
            'tipo' => 'reportFilterTipo',
            default => null,
        };

        if ($property === null) {
            return;
        }

        $this->{$property} = $value;
        $this->reportPage = 1;
    }

    public function updatedReportFilterLocal(): void
    {
        $this->reportPage = 1;
    }

    public function updatedReportFilterAlmacen(): void
    {
        $this->reportPage = 1;
    }

    public function updatedReportFilterItem(): void
    {
        $this->reportPage = 1;
    }

    public function updatedReportFilterTipo(): void
    {
        $this->reportPage = 1;
    }

    /** @return array<string, mixed> */
    protected function buildFilters(int $pagina = 1, int $registros = 25): array
    {
        $state = $this->form->getState();

        return [
            'locales' => implode('-', $state['selectedLocals'] ?? []),
            'estado' => (string) ($state['estado'] ?? '1'),
            'tipo' => (string) ($state['tipo'] ?? '-1'),
            'fechaInicio' => (string) ($this->data['fechaInicio'] ?? now()->toDateString()),
            'fechaFin' => (string) ($this->data['fechaFin'] ?? now()->toDateString()),
            'pagina' => $pagina,
            'registros' => $registros,
            'itemIdList' => implode('-', array_column($this->selectedItems, 'id')),
            'itemTipoList' => implode('-', array_column($this->selectedItems, 'type')),
        ];
    }

    protected function loadStockReport(): void
    {
        $this->isLoading = true;
        $this->resultError = null;

        try {
            // El gateway recorre todas las páginas remotas. La paginación de la
            // tabla es solo visual y no recorta el consolidado ni la exportación.
            $report = $this->gateway()->stockReport($this->buildFilters(1, 50));
            $this->reportMasterRows = $report['master'] ?? [];
            $this->cuadresIncluidos = $report['cuadresIncluidos'] ?? 0;
            $this->cuadresEncontrados = $report['cuadresEncontrados'] ?? $this->cuadresIncluidos;
            $this->paginasConsultadas = $report['paginasConsultadas'] ?? 1;
            $this->hasSearched = true;
            $this->reportPage = 1;
            $this->dispatch('stock-results-ready');
        } catch (Throwable $exception) {
            $this->resultError = $this->friendlyGatewayError($exception);
        } finally {
            $this->isLoading = false;
        }
    }

    /** @return array<int, string> */
    public function reportLocalOptions(): array
    {
        return $this->uniqueReportValues('local');
    }

    /** @return array<int, string> */
    public function reportAlmacenOptions(): array
    {
        return $this->uniqueReportValues('almacen');
    }

    /** @return array<int, string> */
    public function reportItemOptions(): array
    {
        return $this->uniqueReportValues('item');
    }

    /** @return array<int, string> */
    public function reportTipoOptions(): array
    {
        return $this->uniqueReportValues('tipo');
    }

    /** @return array<int, string> */
    private function uniqueReportValues(string $field): array
    {
        $values = array_unique(array_filter(array_column($this->reportMasterRows, $field)));
        sort($values, SORT_STRING | SORT_FLAG_CASE);

        return array_values($values);
    }

    /** @return array<int, array<string, mixed>> */
    protected function filteredReportMaster(): array
    {
        $tableFilters = property_exists($this, 'tableFilters')
            ? ($this->tableFilters['resultados'] ?? [])
            : [];
        $local = (string) ($tableFilters['local'] ?? $this->reportFilterLocal);
        $almacen = (string) ($tableFilters['almacen'] ?? $this->reportFilterAlmacen);
        $item = (string) ($tableFilters['item'] ?? $this->reportFilterItem);
        $tipo = (string) ($tableFilters['tipo'] ?? $this->reportFilterTipo);

        return array_values(array_filter($this->reportMasterRows, function (array $row) use ($local, $almacen, $item, $tipo): bool {
            if ($local !== '' && ($row['local'] ?? '') !== $local) {
                return false;
            }
            if ($almacen !== '' && ($row['almacen'] ?? '') !== $almacen) {
                return false;
            }
            if ($item !== '' && ($row['item'] ?? '') !== $item) {
                return false;
            }
            if ($tipo !== '' && ($row['tipo'] ?? '') !== $tipo) {
                return false;
            }

            return true;
        }));
    }

    /** @return array<int, array<string, mixed>> */
    protected function consolidateByLocalItem(array $rows): array
    {
        $consolidated = [];

        foreach ($rows as $row) {
            $itemKey = $row['itemId'] ?? $row['item'] ?? '';
            $key = ($row['local'] ?? '').'|'.$itemKey.'|'.($row['unidad'] ?? '');
            $consolidated[$key] ??= [
                'itemId' => $row['itemId'] ?? '',
                'itemCodigo' => $row['itemCodigo'] ?? '',
                'local' => $row['local'] ?? '',
                'item' => $row['item'] ?? '',
                'unidad' => $row['unidad'] ?? '',
                'stockActual' => 0.0,
                'almacenes' => [],
            ];
            $consolidated[$key]['stockActual'] += (float) ($row['stockActual'] ?? 0);
            $consolidated[$key]['almacenes'][$row['almacen'] ?? ''] = true;
        }

        $result = array_map(static function (array $row): array {
            $row['almacenes'] = count($row['almacenes']);

            return $row;
        }, array_values($consolidated));

        usort($result, static fn (array $a, array $b): int => [$a['local'], $a['item']] <=> [$b['local'], $b['item']]);

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{rows: array<int, array<string, mixed>>, page: int, pages: int, total: int}
     */
    protected function paginate(array $rows, int $page): array
    {
        $total = count($rows);
        $pages = max(1, (int) ceil($total / max(1, $this->reportPageSize)));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $this->reportPageSize;

        return [
            'rows' => array_slice($rows, $offset, $this->reportPageSize),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ];
    }
}
