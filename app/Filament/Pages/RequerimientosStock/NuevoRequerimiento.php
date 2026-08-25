<?php

namespace App\Filament\Pages\RequerimientosStock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Services\RequerimientoStockGatewayClient;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Nuevo Requerimiento de Stock: replica el flujo de Restaurant.pe Logística
 * (almacen/requerimientos-de-stock/nuevo). Escribe en el ERP -- a diferencia
 * de Kardex/Stock (solo lectura), aquí "Guardar" crea un movimiento real.
 */
class NuevoRequerimiento extends Page
{
    use ScopesLocalsToUser;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Nuevo requerimiento';

    protected static ?string $title = 'Nuevo Requerimiento de Stock';

    protected static string|\UnitEnum|null $navigationGroup = 'Requerimientos de Stock';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'requerimientos-stock/nuevo';

    protected string $view = 'filament.pages.requerimientos-stock.nuevo';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('requerimientos-stock.crear');
    }

    public bool $gatewayUnavailable = false;

    public ?string $loadError = null;

    /** @var array<int, array{id: string, name: string}> */
    public array $availableLocals = [];

    /** @var array<int, array{id: string, nombre: string}> */
    public array $almacenOptions = [];

    public string $localOrigenId = '';

    public string $almacenOrigenId = '';

    public string $localDestinoId = '';

    public string $encargado = '';

    public string $receptor = '';

    public string $observacion = '';

    public string $fecha = '';

    /** Fecha mínima que Restaurant.pe admite para el abastecimiento. */
    public string $fechaMinima = '';

    public bool $esSolicitudCompra = false;

    public string $searchQuery = '';

    /** @var array<int, array<string, mixed>> */
    public array $searchResults = [];

    /** @var array<int, array{item: array<string, mixed>, cantidad: float}> */
    public array $items = [];

    public bool $isSaving = false;

    public ?string $saveError = null;

    public function mount(): void
    {
        try {
            $this->availableLocals = $this->scopeLocalsToUser($this->gateway()->locals());
        } catch (Throwable $exception) {
            $this->gatewayUnavailable = true;
            $this->loadError = $this->friendlyError($exception);

            return;
        }

        $this->localOrigenId = $this->availableLocals[0]['id'] ?? '';
        $this->encargado = trim((string) (auth()->user()?->name ?? ''));
        $this->fechaMinima = $this->fechaMinimaAbastecimiento();
        $this->fecha = $this->fechaSugeridaAbastecimiento();
        $this->refreshAlmacenes();
    }

    public function updatedLocalOrigenId(): void
    {
        $this->refreshAlmacenes();
    }

    protected function refreshAlmacenes(): void
    {
        if ($this->localOrigenId === '') {
            $this->almacenOptions = [];
            $this->almacenOrigenId = '';

            return;
        }

        try {
            $this->almacenOptions = $this->gateway()->almacenes($this->localOrigenId);
        } catch (Throwable $exception) {
            $this->almacenOptions = [];
            $this->loadError = $this->friendlyError($exception);
        }

        $this->almacenOrigenId = $this->almacenOptions[0]['id'] ?? '';
    }

    public function updatedSearchQuery(): void
    {
        $query = trim($this->searchQuery);

        if (mb_strlen($query) < 3) {
            $this->searchResults = [];

            return;
        }

        try {
            $this->searchResults = $this->gateway()->searchItems($query);
        } catch (Throwable $exception) {
            $this->searchResults = [];
            $this->loadError = $this->friendlyError($exception);
        }
    }

    public function agregarItem(int $index): void
    {
        $item = $this->searchResults[$index] ?? null;

        if (! $item) {
            return;
        }

        $itemId = (string) $item['item_id'];

        foreach ($this->items as $existing) {
            if ((string) ($existing['item']['item_id'] ?? '') === $itemId) {
                return;
            }
        }

        $this->items[] = ['item' => $item, 'cantidad' => 1];
        $this->searchQuery = '';
        $this->searchResults = [];
    }

    public function quitarItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function guardar(bool $comoSolicitudCompra = false): void
    {
        $this->saveError = null;

        if ($this->localOrigenId === '' || $this->almacenOrigenId === '' || $this->localDestinoId === '') {
            $this->saveError = 'Selecciona local origen, almacén y local destino.';

            return;
        }

        if (! $this->localAllowedForUser($this->localOrigenId)) {
            $this->saveError = 'No tienes acceso al local de origen seleccionado.';

            return;
        }

        if (trim($this->encargado) === '') {
            $this->saveError = 'Indica el encargado.';

            return;
        }

        try {
            $fechaSeleccionada = Carbon::parse($this->fecha, config('app.timezone'));
        } catch (Throwable) {
            $this->saveError = 'Selecciona una fecha de abastecimiento válida.';

            return;
        }

        if ($fechaSeleccionada->lt(now()->startOfDay()->addDay())) {
            $this->saveError = 'El día de abastecimiento debe ser, como mínimo, mañana. Selecciónalo en el calendario.';

            return;
        }

        if (empty($this->items)) {
            $this->saveError = 'Agrega al menos un ítem al requerimiento.';

            return;
        }

        $this->isSaving = true;

        try {
            $this->gateway()->guardar(
                localOrigenId: $this->localOrigenId,
                almacenOrigenId: $this->almacenOrigenId,
                localDestinoId: $this->localDestinoId,
                encargado: $this->encargado,
                fecha: str_replace('T', ' ', $this->fecha).':00',
                items: $this->items,
                receptor: $this->receptor,
                observacion: $this->observacion,
                esSolicitudCompra: $comoSolicitudCompra,
            );
        } catch (Throwable $exception) {
            $this->saveError = $this->friendlyError($exception);

            return;
        } finally {
            $this->isSaving = false;
        }

        Notification::make()
            ->title($comoSolicitudCompra ? 'Solicitud de compra guardada.' : 'Requerimiento guardado.')
            ->success()
            ->send();

        $this->items = [];
        $this->receptor = '';
        $this->observacion = '';
        $this->fechaMinima = $this->fechaMinimaAbastecimiento();
        $this->fecha = $this->fechaSugeridaAbastecimiento();
    }

    private function gateway(): RequerimientoStockGatewayClient
    {
        return app(RequerimientoStockGatewayClient::class);
    }

    private function fechaMinimaAbastecimiento(): string
    {
        return now()->addDay()->startOfDay()->format('Y-m-d\TH:i');
    }

    private function fechaSugeridaAbastecimiento(): string
    {
        return now()->addDay()->setTime(9, 0)->format('Y-m-d\TH:i');
    }

    private function friendlyError(Throwable $exception): string
    {
        Log::error('[RequerimientosStock] '.$exception->getMessage(), ['exception' => $exception]);

        if (str_contains($exception->getMessage(), 'cURL error 7') || str_contains($exception->getMessage(), 'Connection refused')) {
            return 'No se pudo conectar con el gateway de Stock (D:\DS-TI\API-TI). Verifica que esté corriendo con "npm start" en el puerto configurado.';
        }

        return $exception->getMessage();
    }
}
