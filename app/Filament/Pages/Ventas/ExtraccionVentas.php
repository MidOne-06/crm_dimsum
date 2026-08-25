<?php

namespace App\Filament\Pages\Ventas;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Jobs\ExtraerVentasJob;
use App\Models\Venta;
use App\Models\VentaExtraccion;
use App\Models\VentaExtraccionVenta;
use App\Services\SalesGatewayClient;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\QueryException;
use Throwable;

class ExtraccionVentas extends Page
{
    use ScopesLocalsToUser;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-circle-stack';

    protected static ?string $navigationLabel = 'Extracción de ventas';

    protected static ?string $title = 'Extracción de ventas';

    protected static string|\UnitEnum|null $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 22;

    protected static ?string $slug = 'ventas/extraccion';

    protected string $view = 'filament.pages.ventas.extraccion';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('ventas.extraccion.view');
    }

    public array $availableLocals = [];

    public array $currencyOptions = [];

    public array $documentOptions = [];

    public array $statusOptions = [];

    public array $orderOptions = [];

    public ?array $data = [];

    public string $activeDatePreset = 'today';

    public ?string $resultError = null;

    public ?int $extraccionActualId = null;

    public string $coverageLocalId = '';

    public int $coverageYear;

    public function mount(): void
    {
        $today = now()->toDateString();
        $this->coverageYear = (int) now()->year;

        try {
            $this->availableLocals = $this->scopeLocalsToUser($this->salesGateway()->locals());
            $this->currencyOptions = $this->salesGateway()->currencies();
            $options = $this->salesGateway()->filterOptions();
            $this->documentOptions = $options['comprobantes'] ?? [];
            $this->statusOptions = $options['estados'] ?? [];
            $this->orderOptions = $options['orden'] ?? [];
        } catch (Throwable $exception) {
            $this->resultError = $this->friendlyError($exception);
        }

        $this->form->fill([
            'selectedLocals' => array_column($this->availableLocals, 'id'),
            'currency' => (string) ($this->currencyOptions[0]['id'] ?? '1'),
            'document' => '-1',
            'status' => '1',
            'order' => '1',
        ]);

        $this->data['dateStart'] = $today;
        $this->data['dateEnd'] = $today;

        $this->extraccionActualId = VentaExtraccion::query()->latest('id')->value('id');
        $this->coverageLocalId = (string) ($this->availableLocals[0]['id'] ?? '');
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

    public function syncDateRange(string $start, string $end, string $preset = 'custom'): void
    {
        $this->data['dateStart'] = $start;
        $this->data['dateEnd'] = $end;
        $this->activeDatePreset = $preset;
    }

    public function hayExtraccionEnProgreso(): bool
    {
        return VentaExtraccion::query()->whereIn('estado', ['pendiente', 'planificando', 'en_progreso'])->exists();
    }

    public function iniciarExtraccion(): void
    {
        $this->resultError = null;

        if (! auth()->user()?->hasPermission('ventas.extraccion.iniciar')) {
            $this->resultError = 'No tienes permiso para iniciar una extracción.';

            return;
        }

        if ($this->hayExtraccionEnProgreso()) {
            $this->resultError = 'Ya hay una extracción en progreso. Espera a que termine antes de iniciar otra.';

            return;
        }

        // Se lee directo de $this->data (no de $this->form->getState()): el Schema
        // cachea su propio árbol de componentes y puede quedar desincronizado del
        // valor real entre renders (mismo motivo por el que dateStart/dateEnd ya se
        // leían así, y el mismo bug que se corrigió en HistoricoVentas::query()).
        $start = (string) ($this->data['dateStart'] ?? now()->toDateString());
        $end = (string) ($this->data['dateEnd'] ?? now()->toDateString());

        // Defensa en profundidad: selectedLocals es una propiedad pública de
        // Livewire -- se revalida el valor recibido contra los locales
        // asignados al usuario antes de lanzar la extracción.
        $selectedLocals = $this->restrictLocalIdsToUser($this->data['selectedLocals'] ?? []);

        if (empty($selectedLocals)) {
            $this->resultError = 'Selecciona al menos un local.';

            return;
        }

        if ($end < $start) {
            $this->resultError = 'El rango de fechas no es válido.';

            return;
        }

        $filtros = [
            'locales' => implode('-', $selectedLocals),
            'moneda' => (string) ($this->data['currency'] ?? '1'),
            'comprobante' => (string) ($this->data['document'] ?? '-1'),
            'estado' => (string) ($this->data['status'] ?? '1'),
            'orden' => (string) ($this->data['order'] ?? '1'),
            'fechaInicio' => $start,
            'fechaFin' => $end,
        ];

        try {
            $extraccion = VentaExtraccion::create([
                'estado' => 'pendiente',
                'filtros' => $filtros,
                'iniciado_por' => auth()->id(),
            ]);
        } catch (QueryException) {
            $this->resultError = 'Otra extracción acaba de iniciarse. Actualiza la página para ver su progreso.';

            return;
        }

        ExtraerVentasJob::dispatch($extraccion->id);

        $this->extraccionActualId = $extraccion->id;
    }

    public function refreshExtraccion(): void
    {
        // No-op: el polling solo necesita re-renderizar la página, que vuelve
        // a leer $this->extraccionActual() desde la base de datos.
    }

    public function extraccionActual(): ?VentaExtraccion
    {
        return $this->extraccionActualId ? VentaExtraccion::find($this->extraccionActualId) : null;
    }

    /** @return Collection<int, VentaExtraccion> */
    public function historial(): Collection
    {
        return VentaExtraccion::query()->latest('id')->limit(20)->get();
    }

    /** @return array{ventas: int, corridas: int, fallidas: int, coveragePercent: int} */
    public function resumenGeneral(): array
    {
        $map = $this->coverageMap();
        $yearStart = Carbon::create($this->coverageYear, 1, 1)->startOfDay();
        $today = now()->startOfDay();
        $yearEnd = Carbon::create($this->coverageYear, 12, 31)->endOfDay();
        $limit = $yearEnd->greaterThan($today) ? $today : $yearEnd;
        $totalDias = max(1, $yearStart->diffInDays($limit) + 1);
        $diasCubiertos = collect($map)->filter(fn (string $status) => $status === 'full')->count();

        return [
            'ventas' => Venta::count(),
            'corridas' => VentaExtraccion::count(),
            'fallidas' => VentaExtraccion::where('estado', 'fallido')->count(),
            'coveragePercent' => (int) round(($diasCubiertos / $totalDias) * 100),
        ];
    }

    public function coveragePrevYear(): void
    {
        $this->coverageYear--;
    }

    public function coverageNextYear(): void
    {
        $this->coverageYear++;
    }

    /** @return array<string, string> 'Y-m-d' => 'full'|'partial' */
    public function coverageMap(): array
    {
        if ($this->coverageLocalId === '') {
            return [];
        }

        return $this->coverageForLocal($this->coverageLocalId, $this->coverageYear);
    }

    /** @return array<int, array{start: string, end: string}> */
    public function coverageGaps(): array
    {
        $map = $this->coverageMap();
        $yearStart = Carbon::create($this->coverageYear, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($this->coverageYear, 12, 31)->endOfDay();
        $today = now()->startOfDay();
        $limit = $yearEnd->greaterThan($today) ? $today : $yearEnd;

        if ($yearStart->greaterThan($limit)) {
            return [];
        }

        $gaps = [];
        $gapStart = null;

        foreach (CarbonPeriod::create($yearStart, $limit) as $day) {
            $covered = isset($map[$day->toDateString()]);

            if (! $covered && $gapStart === null) {
                $gapStart = $day->copy();
            }

            if ($covered && $gapStart !== null) {
                $gaps[] = ['start' => $gapStart->toDateString(), 'end' => $day->copy()->subDay()->toDateString()];
                $gapStart = null;
            }
        }

        if ($gapStart !== null) {
            $gaps[] = ['start' => $gapStart->toDateString(), 'end' => $limit->toDateString()];
        }

        return $gaps;
    }

    /** @return array<string, string> 'Y-m-d' => 'full'|'partial' */
    protected function coverageForLocal(string $localId, int $year): array
    {
        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($year, 12, 31)->endOfDay();
        $coverage = [];

        $extracciones = VentaExtraccion::query()
            ->where('estado', 'completado')
            ->whereNotNull('filtros')
            ->get()
            ->filter(function (VentaExtraccion $extraccion) use ($localId, $yearStart, $yearEnd): bool {
                $filtros = $extraccion->filtros;
                $locales = explode('-', (string) ($filtros['locales'] ?? ''));

                if (! in_array($localId, $locales, true)) {
                    return false;
                }

                if (empty($filtros['fechaInicio']) || empty($filtros['fechaFin'])) {
                    return false;
                }

                $start = Carbon::parse($filtros['fechaInicio'])->startOfDay()->max($yearStart);
                $end = Carbon::parse($filtros['fechaFin'])->startOfDay()->min($yearEnd);

                return $start->lessThanOrEqualTo($end);
            })
            ->values();

        if ($extracciones->isEmpty()) {
            return [];
        }

        // Un estado fallido pertenece a una venta concreta. Se agrupa por la
        // fecha y local que vienen en su resumen, no por todo el rango de la
        // extracción: una falla en otro restaurante no afecta esta cobertura.
        $fallosPorExtraccionYDia = VentaExtraccionVenta::query()
            ->whereIn('extraccion_id', $extracciones->pluck('id'))
            ->where('estado', 'fallido')
            ->get(['extraccion_id', 'resumen'])
            ->reduce(function (array $fallos, VentaExtraccionVenta $trabajo) use ($localId, $yearStart, $yearEnd): array {
                $resumen = $trabajo->resumen ?? [];

                if ((string) ($resumen['local_id'] ?? '') !== $localId || empty($resumen['venta_fecha'])) {
                    return $fallos;
                }

                $fecha = Carbon::parse($resumen['venta_fecha'])->toDateString();
                if (Carbon::parse($fecha)->betweenIncluded($yearStart, $yearEnd)) {
                    $fallos[$trabajo->extraccion_id][$fecha] = true;
                }

                return $fallos;
            }, []);

        $extracciones->each(function (VentaExtraccion $extraccion) use ($yearStart, $yearEnd, $fallosPorExtraccionYDia, &$coverage) {
            $filtros = $extraccion->filtros;
            $start = Carbon::parse($filtros['fechaInicio'])->startOfDay()->max($yearStart);
            $end = Carbon::parse($filtros['fechaFin'])->startOfDay()->min($yearEnd);

            foreach (CarbonPeriod::create($start, $end) as $day) {
                $key = $day->toDateString();
                $estado = isset($fallosPorExtraccionYDia[$extraccion->id][$key]) ? 'partial' : 'full';

                // Una extracción posterior que se completa sin fallas para este
                // local/día repara el indicador de una corrida anterior.
                if (($coverage[$key] ?? null) !== 'full') {
                    $coverage[$key] = $estado;
                }
            }
        });

        return $coverage;
    }

    private function salesGateway(): SalesGatewayClient
    {
        return app(SalesGatewayClient::class);
    }

    private function friendlyError(Throwable $exception): string
    {
        Log::error('[Ventas extracción] '.$exception->getMessage(), ['exception' => $exception]);

        if (str_contains($exception->getMessage(), 'cURL error 7') || str_contains($exception->getMessage(), 'Connection refused')) {
            return 'No se pudo conectar con el gateway de Ventas.';
        }

        return $exception->getMessage();
    }
}
