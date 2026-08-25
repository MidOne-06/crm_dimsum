<?php

namespace App\Filament\Concerns;

use App\Services\SalesGatewayClient;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Throwable;

trait InteractsWithSales
{
    use ScopesLocalsToUser;

    public array $availableLocals = [];

    public array $currencyOptions = [];

    public array $documentOptions = [];

    public array $statusOptions = [];

    public array $orderOptions = [];

    public ?array $data = [];

    public array $salesRows = [];

    public int $salesTotal = 0;

    public int $salesPage = 1;

    public int $salesPages = 1;

    public int $salesPageSize = 10;

    public bool $hasSearched = false;

    public bool $isLoading = false;

    public ?string $resultError = null;

    public bool $detailLoading = false;

    public ?string $detailError = null;

    public ?array $detail = null;

    public string $activeDatePreset = 'today';

    public function mountInteractsWithSales(): void
    {
        $today = now()->toDateString();

        try {
            $this->availableLocals = $this->scopeLocalsToUser($this->salesGateway()->locals());
            $this->currencyOptions = $this->salesGateway()->currencies();
            $options = $this->salesGateway()->filterOptions();
            $this->documentOptions = $options['comprobantes'] ?? [];
            $this->statusOptions = $options['estados'] ?? [];
            $this->orderOptions = $options['orden'] ?? [];
        } catch (Throwable $exception) {
            $this->resultError = $this->friendlySalesError($exception);
        }

        $this->form->fill([
            'selectedLocals' => array_column($this->availableLocals, 'id'),
            'currency' => (string) ($this->currencyOptions[0]['id'] ?? '1'),
            'document' => '-1',
            'status' => '1',
            'order' => '1',
        ]);

        // El fill() del schema reemplaza el arreglo $data completo, así que las
        // fechas (que no son campos del schema, viven fuera de él) se fijan después.
        $this->data['dateStart'] = $today;
        $this->data['dateEnd'] = $today;
    }

    /**
     * Único punto de entrada server-side para el selector de rango de fechas:
     * el calendario doble-mes y los atajos (Hoy/Ayer/Últimos 7 días/...) se
     * calculan en Alpine (cliente) y solo se sincronizan al servidor al
     * presionar "Aplicar", para no ir y volver por cada clic en un día.
     */
    public function syncDateRange(string $start, string $end, string $preset = 'custom'): void
    {
        $this->data['dateStart'] = $start;
        $this->data['dateEnd'] = $end;
        $this->activeDatePreset = $preset;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Locales')
                    ->compact()
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        CheckboxList::make('selectedLocals')
                            ->hiddenLabel()
                            ->options(fn (): array => collect($this->availableLocals)->pluck('name', 'id')->all())
                            ->columns(['default' => 1, 'sm' => 2, 'lg' => 3, 'xl' => 4])
                            ->bulkToggleable()
                            ->searchable(),
                    ]),
                Grid::make(['default' => 1, 'md' => 2, 'xl' => 4])
                    ->schema([
                        Select::make('currency')->label('Moneda')->native(false)
                            ->options(fn (): array => collect($this->currencyOptions)->pluck('label', 'id')->all()),
                        Select::make('document')->label('Comprobante')->native(false)
                            ->options(fn (): array => collect($this->documentOptions)->pluck('label', 'value')->all()),
                        Select::make('status')->label('Estado')->native(false)
                            ->options(fn (): array => collect($this->statusOptions)->pluck('label', 'value')->all()),
                        Select::make('order')->label('Orden')->native(false)
                            ->options(fn (): array => collect($this->orderOptions)->pluck('label', 'value')->all()),
                    ]),
            ])
            ->statePath('data');
    }

    public function search(): void
    {
        $this->salesPage = 1;
        $this->loadSales();
    }

    public function goToSalesPage(int $delta): void
    {
        $target = min($this->salesPages, max(1, $this->salesPage + $delta));
        if ($target === $this->salesPage) {
            return;
        }

        $this->salesPage = $target;
        $this->loadSales();
    }

    public function updatedSalesPageSize(): void
    {
        if (! $this->hasSearched) {
            return;
        }

        $this->salesPage = 1;
        $this->loadSales();
    }

    /** Cada página que usa este trait define bajo qué permiso queda "ver detalle". */
    abstract protected function detailPermissionSlug(): string;

    public function openDetail(string $id): void
    {
        if (! auth()->user()?->hasPermission($this->detailPermissionSlug())) {
            return;
        }

        $this->detailLoading = true;
        $this->detailError = null;
        $this->detail = null;

        try {
            $this->detail = $this->salesGateway()->saleDetail($id);
        } catch (Throwable $exception) {
            $this->detailError = $this->friendlySalesError($exception);
        } finally {
            $this->detailLoading = false;
            $this->dispatch('open-modal', id: 'sale-detail-modal');
        }
    }

    public function closeDetail(): void
    {
        $this->detail = null;
        $this->detailError = null;
        $this->dispatch('close-modal', id: 'sale-detail-modal');
    }

    public function salesTotals(): array
    {
        return [
            'subtotal' => array_sum(array_map(fn (array $row): float => (float) ($row['venta_subtotal'] ?? 0), $this->salesRows)),
            'taxes' => array_sum(array_map(fn (array $row): float => (float) ($row['impuestos'] ?? 0), $this->salesRows)),
            'total' => array_sum(array_map(fn (array $row): float => (float) ($row['venta_total'] ?? 0), $this->salesRows)),
        ];
    }

    protected function loadSales(): void
    {
        $this->isLoading = true;
        $this->resultError = null;

        try {
            $result = $this->salesGateway()->sales($this->buildSalesFilters());
            $this->salesRows = $result['rows'] ?? [];
            $this->salesTotal = (int) ($result['total'] ?? 0);
            $this->salesPage = (int) ($result['pagina'] ?? $this->salesPage);
            $this->salesPages = max(1, (int) ($result['paginas'] ?? 1));
            $this->hasSearched = true;
            $this->dispatch('sales-results-ready');
        } catch (Throwable $exception) {
            $this->resultError = $this->friendlySalesError($exception);
        } finally {
            $this->isLoading = false;
        }
    }

    protected function buildSalesFilters(): array
    {
        $state = $this->form->getState();

        // Defensa en profundidad: selectedLocals es una propiedad pública de
        // Livewire -- un usuario restringido a ciertos locales podría editar
        // el payload wire:model y consultar ventas de un local fuera de su
        // alcance. El CheckboxList ya ofrece solo los locales permitidos,
        // pero se revalida aquí el valor efectivamente recibido.
        $selectedLocals = $this->restrictLocalIdsToUser($state['selectedLocals'] ?? []);

        return [
            'locales' => implode('-', $selectedLocals),
            'moneda' => (string) ($state['currency'] ?? '1'),
            'comprobante' => (string) ($state['document'] ?? '-1'),
            'estado' => (string) ($state['status'] ?? '1'),
            'orden' => (string) ($state['order'] ?? '1'),
            'fechaInicio' => (string) ($this->data['dateStart'] ?? now()->toDateString()),
            'fechaFin' => (string) ($this->data['dateEnd'] ?? now()->toDateString()),
            'pagina' => $this->salesPage,
            'registros' => $this->salesPageSize,
        ];
    }

    protected function salesGateway(): SalesGatewayClient
    {
        return app(SalesGatewayClient::class);
    }

    protected function friendlySalesError(Throwable $exception): string
    {
        if (str_contains($exception->getMessage(), 'cURL error 7') || str_contains($exception->getMessage(), 'Connection refused')) {
            return 'No se pudo conectar con el gateway de Ventas.';
        }

        return $exception->getMessage();
    }
}
