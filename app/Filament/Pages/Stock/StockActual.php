<?php

namespace App\Filament\Pages\Stock;

use App\Filament\Concerns\InteractsWithStockFilters;
use App\Models\StockCuadre;
use App\Models\StockCuadreDetalle;
use App\Services\StockActualHistoricoService;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;

class StockActual extends Page implements HasTable
{
    use InteractsWithStockFilters;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationLabel = 'Stock';

    protected static ?string $title = 'Stock';

    protected static string|\UnitEnum|null $navigationGroup = 'Stock Actual';

    protected static ?int $navigationSort = 11;

    protected string $view = 'filament.pages.stock.actual';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('stock.actual.view');
    }

    /** @var array<string, mixed> */
    public array $cuadresHeader = [];

    /** @var array<int, array<string, mixed>> */
    public array $cuadresRows = [];

    public int $cuadresTotal = 0;

    public int $cuadresPagina = 1;

    public int $cuadresRegistros = 10;

    public bool $showDetail = false;

    public bool $detailLoading = false;

    public ?string $detailError = null;

    /** @var array<string, mixed>|null */
    public ?array $detail = null;

    public function search(): void
    {
        $this->isLoading = true;
        $this->resultError = null;
        $this->cuadresPagina = 1;
        $filtros = $this->buildFilters(1, 50);
        $this->hasSearched = true;
        $this->loadStockReport();
        $this->cuadresHeader = app(StockActualHistoricoService::class)->header($filtros);
        $this->cuadresTotal = (int) ($this->cuadresHeader['totalCuadres'] ?? 0);
        $this->isLoading = false;
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (int $page, int $recordsPerPage): LengthAwarePaginator => $this->cuadresRecords($page, $recordsPerPage))
            ->columns([
                TextColumn::make('cuadremanual_id')->label('Cód.')->weight('medium')->sortable(),
                TextColumn::make('cuadremanual_fecha')->label('Fecha')->sortable()->toggleable(),
                TextColumn::make('local_descripcion')->label('Local')
                    ->state(fn (array $record): string => $record['local_descripcion'] ?? $record['cuadremanual_local'] ?? '—')
                    ->searchable()->wrap(),
                TextColumn::make('sobrevalorizacion')->label('Sobrevalorización')->numeric(2)->color('success')->alignEnd(),
                TextColumn::make('perdida')->label('Pérdida')->numeric(2)->color('danger')->alignEnd(),
                TextColumn::make('cuadremanual_razon')->label('Motivo')
                    ->state(fn (array $record): string => $record['cuadremanual_razon'] ?? $record['motivo'] ?? '—')
                    ->wrap()->toggleable(),
                TextColumn::make('usuario_nombre')->label('Responsable')
                    ->state(fn (array $record): string => $record['usuario_nombre'] ?? ($record['usuario']['usuario_nombres'] ?? $record['responsable'] ?? '—'))
                    ->wrap()->toggleable(),
                TextColumn::make('tipo_cuadre')->label('Tipo')
                    ->state(fn (array $record): string => $record['tipo_cuadre'] ?? $record['tipo'] ?? '—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('estado')->label('Estado')->badge()->toggleable(),
            ])
            ->recordActions([
                Action::make('ver')
                    ->label('Ver')
                    ->icon('heroicon-o-eye')
                    ->visible(fn (): bool => (bool) auth()->user()?->hasPermission('stock.actual.ver-detalle'))
                    ->action(fn (array $record): mixed => $this->openDetail((string) ($record['cuadremanual_id'] ?? ''))),
            ])
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('No hay registros para los filtros seleccionados.');
    }

    protected function cuadresRecords(int $page, int $recordsPerPage): LengthAwarePaginator
    {
        if (! $this->hasSearched) {
            return new LengthAwarePaginator(collect(), 0, $recordsPerPage, $page);
        }

        $paginator = app(StockActualHistoricoService::class)->cuadres($this->buildFilters(), $page, $recordsPerPage);
        $paginator->through(fn (StockCuadre $cuadre): array => $this->cuadreRow($cuadre));
        $this->cuadresTotal = $paginator->total();
        return $paginator;
    }

    public function goToCuadresPage(int $delta): void
    {
        $target = max(1, $this->cuadresPagina + $delta);
        if ($target === $this->cuadresPagina) {
            return;
        }

        $this->cuadresPagina = $target;
        $this->resetTable();
    }

    public function updatedCuadresRegistros(): void
    {
        if (! $this->hasSearched) {
            return;
        }

        $this->cuadresPagina = 1;
        $this->resetTable();
    }

    public function openDetail(string $id): void
    {
        if (! auth()->user()?->hasPermission('stock.actual.ver-detalle')) {
            return;
        }

        $this->showDetail = true;
        $this->detailLoading = false;
        $this->detailError = null;
        $this->detail = null;
        $cuadre = StockCuadre::query()->with(['detalles' => fn ($query) => $query->where('activo', true)->orderBy('id')])->where('restaurant_id', $id)->first();
        if (! $cuadre) $this->detailError = 'El detalle aún no está disponible en la copia local.';
        else $this->detail = $this->detailLocal($cuadre);
        $this->dispatch('open-modal', id: 'stock-detail-modal');
    }

    public function closeDetail(): void
    {
        $this->showDetail = false;
        $this->detail = null;
        $this->detailError = null;
        $this->dispatch('close-modal', id: 'stock-detail-modal');
    }

    /** @return array<int, array<string, mixed>> */
    public function detailTableRows(): array
    {
        return collect($this->detail['items'] ?? [])->map(fn (array $item): array => [
            'item' => $item['item'] ?? '—',
            'almacen' => $item['almacen'] ?? '—',
            'aumento' => $item['aumento'] ?? 0,
            'disminucion' => $item['disminuyo'] ?? 0,
            'costo' => $item['costo'] ?? 0,
            'impuestos' => $item['impuestos'] ?? 0,
            'total' => $item['total'] ?? 0,
            'stock_anterior' => $item['stockAnterior'] ?? 0,
            'stock_actual' => $item['stockActual'] ?? 0,
            'valorizacion' => $item['valorizacion'] ?? 0,
        ])->all();
    }

    /** @return array<string, array{label: string, numeric?: bool, decimals?: int}> */
    public function detailTableColumns(): array
    {
        return [
            'item' => ['label' => 'Ítem'],
            'almacen' => ['label' => 'Almacén'],
            'aumento' => ['label' => 'Aumento', 'numeric' => true, 'decimals' => 3],
            'disminucion' => ['label' => 'Disminución', 'numeric' => true, 'decimals' => 3],
            'costo' => ['label' => 'Costo', 'numeric' => true, 'decimals' => 2],
            'impuestos' => ['label' => 'Impuestos', 'numeric' => true, 'decimals' => 2],
            'total' => ['label' => 'Total', 'numeric' => true, 'decimals' => 2],
            'stock_anterior' => ['label' => 'Stock anterior', 'numeric' => true, 'decimals' => 3],
            'stock_actual' => ['label' => 'Stock actual', 'numeric' => true, 'decimals' => 3],
            'valorizacion' => ['label' => 'Valorización', 'numeric' => true, 'decimals' => 2],
        ];
    }

    /** @return array{rows: array<int, array<string, mixed>>, page: int, pages: int, total: int} */
    public function masterPage(): array
    {
        return $this->paginate($this->filteredReportMaster(), $this->reportPage);
    }

    protected function loadStockReport(): void
    {
        $service = app(StockActualHistoricoService::class);
        $filtros = $this->buildFilters();
        $this->reportMasterRows = $service->maestro($filtros);
        $header = $service->header($filtros);
        $this->cuadresIncluidos = (int) $header['totalCuadres'];
        $this->cuadresEncontrados = $this->cuadresIncluidos;
        $this->paginasConsultadas = 0;
        $this->reportPage = 1;
        $this->dispatch('stock-results-ready');
    }

    /** Carga filtros desde la copia local, sin esperar a Restaurant. */
    public function mountInteractsWithStockFilters(): void
    {
        $this->availableLocals = StockCuadre::query()
            ->whereNotNull('local_id')->select('local_id', 'local_nombre')->distinct()->orderBy('local_nombre')->get()
            ->map(fn (StockCuadre $cuadre): array => ['id' => $cuadre->local_id, 'name' => $cuadre->local_nombre ?: $cuadre->local_id])->all();
        $this->estadoOptions = [['value' => '-1', 'label' => 'Todos'], ['value' => '1', 'label' => 'Activo'], ['value' => '0', 'label' => 'Inactivo']];
        $this->tipoOptions = [['value' => '-1', 'label' => 'Todos'], ['value' => '0', 'label' => 'Cuadre de stock normal'], ['value' => '1', 'label' => 'Cuadre de stock ciego'], ['value' => '2', 'label' => 'Cuadre de stock por archivo']];
        $this->form->fill(['selectedLocals' => array_column($this->availableLocals, 'id'), 'estado' => '-1', 'tipo' => '-1']);
        $this->data['fechaInicio'] = now()->toDateString();
        $this->data['fechaFin'] = now()->toDateString();
    }

    /** Selector local de ítems para no consultar Restaurant desde la pantalla. */
    public function updatedItemSearch(): void
    {
        $query = trim($this->itemSearch);
        if ($query === '') { $this->itemSuggestions = []; return; }
        $this->itemSuggestions = StockCuadreDetalle::query()->where('activo', true)
            ->where(fn ($q) => $q->whereRaw('item ILIKE ?', ["%{$query}%"])->orWhereRaw('item_codigo ILIKE ?', ["%{$query}%"]))
            ->select('item_id', 'tipo', 'item', 'item_codigo')->distinct()->limit(50)->get()
            ->map(fn (StockCuadreDetalle $item): array => ['id' => (string) $item->item_id, 'type' => (string) $item->tipo, 'subtype' => null, 'name' => (string) $item->item, 'code' => (string) $item->item_codigo])
            ->filter(fn (array $item): bool => ! collect($this->selectedItems)->contains(fn (array $selected): bool => $selected['id'] === $item['id'] && $selected['type'] === $item['type']))->values()->all();
    }

    /** @return array<string,mixed> */
    private function cuadreRow(StockCuadre $cuadre): array
    {
        return ['cuadremanual_id' => $cuadre->restaurant_id, 'cuadremanual_fecha' => $cuadre->fecha_cuadre?->format('d/m/Y H:i'), 'local_descripcion' => $cuadre->local_nombre, 'sobrevalorizacion' => $cuadre->sobrevalorizacion, 'perdida' => $cuadre->perdida, 'cuadremanual_razon' => $cuadre->motivo, 'usuario_nombre' => $cuadre->responsable, 'tipo_cuadre' => $cuadre->tipo, 'estado' => $cuadre->estado];
    }

    /** @return array<string,mixed> */
    private function detailLocal(StockCuadre $cuadre): array
    {
        return ['id' => $cuadre->restaurant_id, 'motivo' => $cuadre->motivo, 'fechaCuadre' => $cuadre->fecha_cuadre?->format('d/m/Y H:i'), 'fechaRegistro' => $cuadre->fecha_registro?->format('d/m/Y H:i'), 'estado' => $cuadre->estado, 'local' => $cuadre->local_nombre, 'registradoPor' => $cuadre->responsable, 'items' => $cuadre->detalles->map(fn ($item) => ['item' => $item->item, 'almacen' => $item->almacen, 'aumento' => $item->aumento, 'disminuyo' => $item->disminucion, 'costo' => $item->costo, 'impuestos' => $item->impuestos, 'total' => $item->total, 'stockAnterior' => $item->stock_anterior, 'stockActual' => $item->stock_actual, 'valorizacion' => $item->valorizacion])->all()];
    }
}
