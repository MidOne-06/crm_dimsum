<?php

namespace App\Filament\Pages\Kardex;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Services\KardexGatewayClient;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Kardex General: replica el flujo real de Restaurant.pe Logística
 * (Filtra -> Descargar). No es una consulta interactiva -- genera y
 * descarga el mismo reporte xlsx/csv que el ERP, en sus 3 versiones.
 * Solo lectura, no escribe nada en el ERP.
 */
class KardexGeneral extends Page
{
    use ScopesLocalsToUser;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Kardex';

    protected static ?string $title = 'Kardex';

    protected static string|\UnitEnum|null $navigationGroup = 'Kardex';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'kardex';

    protected string $view = 'filament.pages.kardex.general';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('kardex.view');
    }

    public bool $gatewayUnavailable = false;

    public ?string $filtersError = null;

    /** @var array<int, array{id: string, name: string}> */
    public array $availableLocals = [];

    /** @var array<int, array{id: string, nombre: string}> */
    public array $almacenOptions = [];

    /** @var array<int, array{id: int, label: string}> */
    public array $motivoOptions = [];

    /** @var array<string, mixed> */
    public ?array $data = [];

    public string $fechaInicio = '';

    public string $fechaFin = '';

    public bool $kardexValorizado = true;

    public bool $verPrecioSinImpuestos = false;

    public bool $incluirDerivados = true;

    public bool $incluirInsumos = true;

    public bool $incluirProductos = true;

    public string $version = '1';

    public bool $isDownloading = false;

    public ?string $downloadError = null;

    public function mount(): void
    {
        try {
            $this->availableLocals = $this->scopeLocalsToUser($this->gateway()->locals());
            $this->motivoOptions = $this->gateway()->motivos();
        } catch (Throwable $exception) {
            $this->gatewayUnavailable = true;
            $this->filtersError = $this->friendlyError($exception);

            return;
        }

        $firstLocal = $this->availableLocals[0]['id'] ?? '';

        $this->form->fill([
            'local_id' => $firstLocal,
            'almacen_id' => '-1',
            'motivo_id' => '-1',
        ]);

        $this->refreshAlmacenes($firstLocal);

        $this->fechaInicio = now()->startOfMonth()->format('Y-m-d');
        $this->fechaFin = now()->format('Y-m-d');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['default' => 1, 'md' => 3])
                    ->schema([
                        Select::make('local_id')
                            ->label('Local')
                            ->native(false)
                            ->searchable()
                            ->live()
                            ->options(fn (): array => collect($this->availableLocals)->pluck('name', 'id')->all())
                            ->afterStateUpdated(fn (?string $state) => $this->refreshAlmacenes($state ?? '')),
                        Select::make('almacen_id')
                            ->label('Almacén')
                            ->native(false)
                            ->searchable()
                            ->options(fn (): array => collect($this->almacenOptions)->pluck('nombre', 'id')->all()),
                        Select::make('motivo_id')
                            ->label('Motivo/movimiento')
                            ->native(false)
                            ->searchable()
                            ->options(fn (): array => collect($this->motivoOptions)->pluck('label', 'id')->all()),
                    ]),
            ])
            ->statePath('data');
    }

    protected function refreshAlmacenes(string $localId): void
    {
        try {
            $almacenes = $localId !== '' ? $this->gateway()->almacenes($localId) : [];
        } catch (Throwable $exception) {
            $almacenes = [];
            $this->downloadError = $this->friendlyError($exception);
        }

        $this->almacenOptions = [
            ['id' => '-1', 'nombre' => 'Todos'],
            ...$almacenes,
        ];

        $this->data['almacen_id'] = '-1';
    }

    public function descargar(string $type): ?StreamedResponse
    {
        $this->downloadError = null;

        if (! $this->incluirDerivados && ! $this->incluirInsumos && ! $this->incluirProductos) {
            $this->downloadError = 'Selecciona al menos un tipo de ítem (Derivados, Insumos o Productos).';

            return null;
        }

        $state = $this->form->getState();
        $localId = (string) ($state['local_id'] ?? '');

        if ($localId === '') {
            $this->downloadError = 'Selecciona un local.';

            return null;
        }

        $this->isDownloading = true;

        try {
            $reporte = $this->gateway()->reporte([
                'local_id' => $localId,
                'almacen_id' => (string) ($state['almacen_id'] ?? '-1'),
                'motivo' => (string) ($state['motivo_id'] ?? '-1'),
                'fecha_inicio' => $this->fechaInicio,
                'fecha_fin' => $this->fechaFin,
                'kardex_valorizado' => $this->kardexValorizado ? '1' : '0',
                'vercostosinimpuesto' => $this->verPrecioSinImpuestos ? '1' : '0',
                'tipo_producto' => $this->incluirProductos ? '1' : '0',
                'tipo_insumo' => $this->incluirInsumos ? '1' : '0',
                'tipo_derivado' => $this->incluirDerivados ? '1' : '0',
                'type' => $type,
                'version' => $this->version,
            ]);
        } catch (Throwable $exception) {
            $this->downloadError = $this->friendlyError($exception);

            return null;
        } finally {
            $this->isDownloading = false;
        }

        $extension = $type === 'csv' ? 'csv' : 'xlsx';
        $filename = 'kardex-v'.$this->version.'-'.now()->format('Y-m-d_His').'.'.$extension;

        return response()->streamDownload(function () use ($reporte) {
            echo $reporte['content'];
        }, $filename, [
            'Content-Type' => $reporte['contentType'],
        ]);
    }

    private function gateway(): KardexGatewayClient
    {
        return app(KardexGatewayClient::class);
    }

    private function friendlyError(Throwable $exception): string
    {
        Log::error('[Kardex] '.$exception->getMessage(), ['exception' => $exception]);

        if (str_contains($exception->getMessage(), 'cURL error 7') || str_contains($exception->getMessage(), 'Connection refused')) {
            return 'No se pudo conectar con el gateway de Stock (D:\DS-TI\API-TI). Verifica que esté corriendo con "npm start" en el puerto configurado.';
        }

        return $exception->getMessage();
    }
}
