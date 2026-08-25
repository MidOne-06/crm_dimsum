<?php

namespace App\Filament\Pages\Stock;

use App\Filament\Concerns\InteractsWithStockFilters;
use Filament\Pages\Page;
use Throwable;

class StockActual extends Page
{
    use InteractsWithStockFilters;

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

    public int $detailPage = 1;

    public int $detailPageSize = 10;

    public function search(): void
    {
        $this->isLoading = true;
        $this->resultError = null;
        $this->cuadresPagina = 1;
        $cuadresLoaded = false;

        try {
            $result = $this->gateway()->cuadres($this->buildFilters($this->cuadresPagina, $this->cuadresRegistros));
            $this->cuadresHeader = $result['header'] ?? [];
            $this->cuadresRows = $result['rows'] ?? [];
            $this->cuadresTotal = (int) ($result['total'] ?? 0);
            $this->hasSearched = true;
            $cuadresLoaded = true;
        } catch (Throwable $exception) {
            $this->resultError = $this->friendlyGatewayError($exception);
        } finally {
            $this->isLoading = false;
        }

        if (! $cuadresLoaded) {
            return;
        }

        $this->loadStockReport();
    }

    public function goToCuadresPage(int $delta): void
    {
        $target = max(1, $this->cuadresPagina + $delta);
        if ($target === $this->cuadresPagina) {
            return;
        }

        $this->cuadresPagina = $target;
        $this->isLoading = true;

        try {
            $result = $this->gateway()->cuadres($this->buildFilters($this->cuadresPagina, $this->cuadresRegistros));
            $this->cuadresHeader = $result['header'] ?? [];
            $this->cuadresRows = $result['rows'] ?? [];
            $this->cuadresTotal = (int) ($result['total'] ?? 0);
        } catch (Throwable $exception) {
            $this->resultError = $this->friendlyGatewayError($exception);
        } finally {
            $this->isLoading = false;
        }
    }

    public function updatedCuadresRegistros(): void
    {
        if (! $this->hasSearched) {
            return;
        }

        $this->cuadresPagina = 1;
        $this->isLoading = true;

        try {
            $result = $this->gateway()->cuadres($this->buildFilters($this->cuadresPagina, $this->cuadresRegistros));
            $this->cuadresHeader = $result['header'] ?? [];
            $this->cuadresRows = $result['rows'] ?? [];
            $this->cuadresTotal = (int) ($result['total'] ?? 0);
        } catch (Throwable $exception) {
            $this->resultError = $this->friendlyGatewayError($exception);
        } finally {
            $this->isLoading = false;
        }
    }

    public function openDetail(string $id): void
    {
        if (! auth()->user()?->hasPermission('stock.actual.ver-detalle')) {
            return;
        }

        $this->showDetail = true;
        $this->detailLoading = true;
        $this->detailError = null;
        $this->detail = null;
        $this->detailPage = 1;

        try {
            $this->detail = $this->gateway()->cuadreDetail($id);
        } catch (Throwable $exception) {
            $this->detailError = $this->friendlyGatewayError($exception);
        } finally {
            $this->detailLoading = false;
            $this->dispatch('open-modal', id: 'stock-detail-modal');
        }
    }

    public function closeDetail(): void
    {
        $this->showDetail = false;
        $this->detail = null;
        $this->detailError = null;
        $this->dispatch('close-modal', id: 'stock-detail-modal');
    }

    public function goToDetailPage(int $delta): void
    {
        $pages = max(1, (int) ceil(count($this->detail['items'] ?? []) / max(1, $this->detailPageSize)));
        $this->detailPage = min($pages, max(1, $this->detailPage + $delta));
    }

    public function updatedDetailPageSize(): void
    {
        $this->detailPage = 1;
    }

    /** @return array{rows: array<int, array<string, mixed>>, page: int, pages: int, total: int} */
    public function detailItemsPage(): array
    {
        $items = $this->detail['items'] ?? [];
        $total = count($items);
        $pages = max(1, (int) ceil($total / max(1, $this->detailPageSize)));
        $page = min($pages, max(1, $this->detailPage));

        return [
            'rows' => array_slice($items, ($page - 1) * $this->detailPageSize, $this->detailPageSize),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ];
    }

    /** @return array{rows: array<int, array<string, mixed>>, page: int, pages: int, total: int} */
    public function masterPage(): array
    {
        return $this->paginate($this->filteredReportMaster(), $this->reportPage);
    }
}
