<?php

namespace App\Filament\Pages\RequerimientosStock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Services\RequerimientoStockGatewayClient;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImportarPlantilla extends Page
{
    use ScopesLocalsToUser;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-arrow-down';
    protected static ?string $navigationLabel = 'Importar plantilla';
    protected static ?string $title = 'Importar plantillas de requerimiento';
    protected static string|\UnitEnum|null $navigationGroup = 'Requerimientos de Stock';
    protected static ?int $navigationSort = 15;
    protected static ?string $slug = 'requerimientos-stock/importar-plantilla';
    protected string $view = 'filament.pages.requerimientos-stock.importar-plantilla';

    public array $availableLocals = [];
    public string $localId = '';
    public int $page = 1;
    public int $pageSize = 25;
    public int $total = 0;
    public array $plantillas = [];
    public ?array $plantillaSeleccionada = null;
    public bool $incluirCantidadesCero = true;
    public ?string $loadError = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('requerimientos-stock.crear');
    }

    public function mount(): void
    {
        try {
            $this->availableLocals = $this->scopeLocalsToUser($this->gateway()->locals());
            if (! auth()->user()?->isRestrictedToLocals()) {
                array_unshift($this->availableLocals, ['id' => '-1', 'name' => 'Todos']);
            }
            $this->localId = $this->availableLocals[0]['id'] ?? '';
            $this->cargarPlantillas();
        } catch (Throwable $exception) {
            $this->loadError = $this->friendlyError($exception);
        }
    }

    public function updatedLocalId(): void
    {
        $this->page = 1;
        $this->plantillaSeleccionada = null;
        $this->cargarPlantillas();
    }

    public function seleccionarPlantilla(string $id): void
    {
        $this->plantillaSeleccionada = collect($this->plantillas)->first(fn (array $plantilla): bool => $plantilla['id'] === $id);
    }

    public function importar(): void
    {
        if (! $this->plantillaSeleccionada) {
            Notification::make()->title('Selecciona una plantilla para importar.')->warning()->send();
            return;
        }

        $templateId = (string) ($this->plantillaSeleccionada['id'] ?? '');
        $plantilla = collect($this->plantillas)->first(fn (array $row): bool => $row['id'] === $templateId);
        if (! $plantilla || ! $this->localAllowedForUser((string) $plantilla['local_origen_id'])) {
            Notification::make()->title('La plantilla seleccionada no está disponible para tu usuario.')->danger()->send();
            return;
        }

        try {
            $importada = $this->gateway()->importarPlantilla($templateId, $this->incluirCantidadesCero);
            if (empty($importada['items'])) {
                Notification::make()->title('La plantilla no contiene ítems con la opción seleccionada.')->warning()->send();
                return;
            }
            session(['requerimientos-stock.plantilla-importada' => $importada]);
            $this->redirect(NuevoRequerimiento::getUrl());
        } catch (Throwable $exception) {
            $this->loadError = $this->friendlyError($exception);
        }
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, min($page, $this->pages()));
        $this->cargarPlantillas();
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / $this->pageSize));
    }

    private function cargarPlantillas(): void
    {
        if ($this->localId === '' || ! $this->localAllowedForUser($this->localId)) return;
        try {
            $result = $this->gateway()->plantillas($this->localId, $this->page, $this->pageSize);
            $this->plantillas = $result['rows'] ?? [];
            $this->total = (int) ($result['total'] ?? count($this->plantillas));
        } catch (Throwable $exception) {
            $this->plantillas = [];
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
        Log::error('[ImportarPlantillaRequerimiento] '.$exception->getMessage(), ['exception' => $exception]);
        return 'No se pudieron cargar las plantillas: '.$exception->getMessage();
    }
}
