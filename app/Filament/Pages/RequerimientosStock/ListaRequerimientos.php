<?php

namespace App\Filament\Pages\RequerimientosStock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Services\RequerimientoStockGatewayClient;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class ListaRequerimientos extends Page
{
    use ScopesLocalsToUser;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationLabel = 'Lista de requerimientos';
    protected static ?string $title = 'Requerimientos de Stock';
    protected static string|\UnitEnum|null $navigationGroup = 'Requerimientos de Stock';
    protected static ?int $navigationSort = 5;
    protected static ?string $slug = 'requerimientos-stock';
    protected string $view = 'filament.pages.requerimientos-stock.lista';

    public array $availableLocals = [];
    public array $selectedLocals = [];
    public array $selectedProductionLocals = [];
    public string $fechaInicio = '';
    public string $fechaFin = '';
    public string $fechaTipo = '0';
    public string $estado = '-1';
    public string $codigo = '';
    public string $encargado = '';
    public string $itemSearch = '';
    public array $itemResults = [];
    public array $selectedItems = [];
    public int $page = 1;
    public int $pageSize = 25;
    public int $total = 0;
    public array $rows = [];
    public ?string $loadError = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('requerimientos-stock.crear');
    }

    public function mount(): void
    {
        try {
            $this->availableLocals = $this->scopeLocalsToUser($this->gateway()->locals());
            $ids = array_map(fn (array $local): string => (string) $local['id'], $this->availableLocals);
            $this->selectedLocals = $ids;
            $this->selectedProductionLocals = $ids;
            $this->fechaInicio = now()->toDateString();
            $this->fechaFin = now()->toDateString();
            $this->buscar();
        } catch (Throwable $exception) {
            $this->loadError = $this->friendlyError($exception);
        }
    }

    public function buscar(): void
    {
        $this->page = 1;
        $this->loadRows();
    }

    public function updatedPageSize(): void
    {
        $this->buscar();
    }

    public function updatedItemSearch(): void
    {
        $query = trim($this->itemSearch);
        $this->itemResults = mb_strlen($query) >= 3 ? $this->gateway()->searchItems($query) : [];
    }

    public function agregarItemFiltro(int $index): void
    {
        $item = $this->itemResults[$index] ?? null;
        if (! $item || count($this->selectedItems) >= 5) return;
        foreach ($this->selectedItems as $selected) {
            if ((string) $selected['id'] === (string) $item['item_id'] && (string) $selected['tipo'] === (string) $item['item_tipo']) return;
        }
        $this->selectedItems[] = ['id' => (string) $item['item_id'], 'tipo' => (string) $item['item_tipo'], 'nombre' => (string) $item['item_descripcion']];
        $this->itemSearch = '';
        $this->itemResults = [];
    }

    public function quitarItemFiltro(int $index): void
    {
        unset($this->selectedItems[$index]);
        $this->selectedItems = array_values($this->selectedItems);
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, min($page, $this->pages()));
        $this->loadRows();
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / $this->pageSize));
    }

    private function loadRows(): void
    {
        $this->loadError = null;
        try {
            $result = $this->gateway()->lista([
                'pagina' => $this->page,
                'registros' => $this->pageSize,
                'fecha_inicio' => Carbon::parse($this->fechaInicio)->toDateString(),
                'fecha_fin' => Carbon::parse($this->fechaFin)->toDateString(),
                'locales' => $this->restrictLocalIdsToUser($this->selectedLocals),
                'locales_produccion' => $this->restrictLocalIdsToUser($this->selectedProductionLocals),
                'estado' => $this->estado,
                'codigo' => trim($this->codigo),
                'encargado' => trim($this->encargado),
                'por_fecha' => $this->fechaTipo,
                'items' => $this->selectedItems,
            ]);
            $this->rows = $result['rows'] ?? [];
            $this->total = (int) ($result['total'] ?? count($this->rows));
        } catch (Throwable $exception) {
            $this->rows = [];
            $this->total = 0;
            $this->loadError = $this->friendlyError($exception);
        }
    }

    private function gateway(): RequerimientoStockGatewayClient
    {
        return app(RequerimientoStockGatewayClient::class);
    }

    private function friendlyError(Throwable $exception): string
    {
        Log::error('[ListaRequerimientosStock] '.$exception->getMessage(), ['exception' => $exception]);
        return 'No se pudo cargar la lista: '.$exception->getMessage();
    }
}
