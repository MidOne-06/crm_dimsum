<?php

namespace App\Filament\Pages\Kardex;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Jobs\ExtraerKardexJob;
use App\Models\KardexExtraccion as KardexExtraccionModel;
use App\Models\KardexExtraccionLocal;
use App\Models\KardexMovimiento;
use App\Services\KardexGatewayClient;
use Carbon\CarbonPeriod;
use Filament\Forms\Components\CheckboxList;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Extracción de Kardex: mismo patrón que Extracción de Ventas (locales +
 * rango de fechas -> job en background por local -> guarda en
 * kardex_movimientos), pero sin paginación -- el reporte de Restaurant.pe
 * trae todo el rango de un local en una sola llamada.
 */
class KardexExtraccion extends Page
{
    use ScopesLocalsToUser;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-circle-stack';

    protected static ?string $navigationLabel = 'Extracción de kardex';

    protected static ?string $title = 'Extracción de kardex';

    protected static string|\UnitEnum|null $navigationGroup = 'Kardex';

    protected static ?int $navigationSort = 31;

    protected static ?string $slug = 'kardex/extraccion';

    protected string $view = 'filament.pages.kardex.extraccion';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('kardex.extraccion.view');
    }

    public array $availableLocals = [];

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
            $this->availableLocals = $this->scopeLocalsToUser($this->gateway()->locals());
        } catch (Throwable $exception) {
            $this->resultError = $this->friendlyError($exception);
        }

        $this->form->fill([
            'selectedLocals' => array_column($this->availableLocals, 'id'),
        ]);

        $this->data['dateStart'] = now()->startOfMonth()->toDateString();
        $this->data['dateEnd'] = $today;

        $this->extraccionActualId = KardexExtraccionModel::query()->latest('id')->value('id');
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
            ])
            ->statePath('data');
    }

    public function abrirFiltrosExtraccion(): void
    {
        $this->dispatch('open-modal', id: 'filtros-extraccion-kardex');
    }

    public function cerrarFiltrosExtraccion(): void
    {
        $this->dispatch('close-modal', id: 'filtros-extraccion-kardex');
    }

    public function syncDateRange(string $start, string $end, string $preset = 'custom'): void
    {
        $this->data['dateStart'] = $start;
        $this->data['dateEnd'] = $end;
        $this->activeDatePreset = $preset;
    }

    /** @return array{0: string, 1: string} */
    public function dateRangeForDisplay(): array
    {
        return [
            (string) ($this->data['dateStart'] ?? now()->startOfMonth()->toDateString()),
            (string) ($this->data['dateEnd'] ?? now()->toDateString()),
        ];
    }

    public function usesHistoricalCoverage(): bool
    {
        return false;
    }

    public function hayExtraccionEnProgreso(): bool
    {
        return KardexExtraccionModel::query()->whereIn('estado', ['pendiente', 'en_progreso'])->exists();
    }

    public function iniciarExtraccion(): void
    {
        $this->resultError = null;

        if (! auth()->user()?->hasPermission('kardex.extraccion.iniciar')) {
            $this->resultError = 'No tienes permiso para iniciar una extracción.';

            return;
        }

        if ($this->hayExtraccionEnProgreso()) {
            $this->resultError = 'Ya hay una extracción en progreso. Espera a que termine antes de iniciar otra.';

            return;
        }

        // Defensa en profundidad: $this->data['selectedLocals'] es una propiedad
        // pública de Livewire -- un usuario restringido a ciertos locales podría
        // editar el payload wire:model y pedir locales fuera de su alcance. La
        // lista de opciones del CheckboxList ya viene filtrada, pero se
        // revalida aquí el valor efectivamente recibido antes de usarlo.
        $selectedLocals = $this->restrictLocalIdsToUser($this->data['selectedLocals'] ?? []);

        if (empty($selectedLocals)) {
            $this->resultError = 'Selecciona al menos un local.';

            return;
        }

        $start = (string) ($this->data['dateStart'] ?? now()->toDateString());
        $end = (string) ($this->data['dateEnd'] ?? now()->toDateString());

        $validator = Validator::make([
            'fecha_inicio' => $start,
            'fecha_fin' => $end,
        ], [
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date', 'after_or_equal:fecha_inicio'],
        ]);

        if ($validator->fails()) {
            $this->resultError = 'El rango de fechas no es válido. La fecha final debe ser igual o posterior a la inicial.';

            return;
        }

        // Los reportes de Restaurant son XLSX completos. Limitar cada
        // extracción a 31 días evita archivos excesivos y hace que el
        // reintento por local sea seguro y predecible. El histórico se carga
        // por meses consecutivos desde la misma pantalla.
        if (Carbon::parse($start)->diffInDays(Carbon::parse($end)) > 30) {
            $this->resultError = 'El rango máximo por extracción es de 31 días. Ejecuta los meses por separado para proteger la integridad de los datos.';

            return;
        }
        $nombres = collect($this->availableLocals)->pluck('name', 'id')->all();

        $filtros = [
            'locales' => implode('-', $selectedLocals),
            'localesNombres' => array_intersect_key($nombres, array_flip($selectedLocals)),
            'motivo' => '-1',
            'fechaInicio' => $start,
            'fechaFin' => $end,
        ];

        try {
            $extraccion = KardexExtraccionModel::create([
                'estado' => 'pendiente',
                'filtros' => $filtros,
                'iniciado_por' => auth()->id(),
            ]);
        } catch (QueryException) {
            $this->resultError = 'Otra extracción acaba de iniciarse. Actualiza la página para ver su progreso.';

            return;
        }

        ExtraerKardexJob::dispatch($extraccion->id)->onQueue('kardex');

        $this->extraccionActualId = $extraccion->id;
    }

    /**
     * "Best effort": ningún job puede matarse a mitad de una descarga en
     * curso, pero cada job ya valida el estado de la extracción/local antes
     * de empezar a trabajar (ver ExtraerKardexJob y ProcesarLocalKardexJob) --
     * así que anular solo necesita cambiar esos estados. Un local que ya
     * estaba "en_progreso" al momento de anular termina su descarga normal.
     */
    public function anularExtraccion(): void
    {
        $this->resultError = null;

        if (! auth()->user()?->hasPermission('kardex.extraccion.anular')) {
            $this->resultError = 'No tienes permiso para anular la extracción.';

            return;
        }

        $extraccion = $this->extraccionActual();

        if (! $extraccion || ! in_array($extraccion->estado, ['pendiente', 'en_progreso'], true)) {
            return;
        }

        DB::transaction(function () use ($extraccion): void {
            $extraccion->locales()->where('estado', 'pendiente')->update([
                'estado' => 'cancelado',
                'mensaje_error' => 'Extracción anulada antes de procesar este local.',
            ]);

            $extraccion->update([
                'estado' => 'cancelado',
                'mensaje_error' => 'Anulada por '.(auth()->user()->name ?? 'un usuario').'.',
                'completado_at' => now(),
            ]);
        });
    }

    public function refreshExtraccion(): void
    {
        // No-op: el polling solo necesita re-renderizar, que vuelve a leer
        // extraccionActual() desde la base de datos.
    }

    public function extraccionActual(): ?KardexExtraccionModel
    {
        return $this->extraccionActualId ? KardexExtraccionModel::find($this->extraccionActualId) : null;
    }

    /** @return array{movimientos: int, corridas: int, fallidas: int, coveragePercent: int} */
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
            'movimientos' => KardexMovimiento::count(),
            'corridas' => KardexExtraccionModel::count(),
            'fallidas' => KardexExtraccionModel::where('estado', 'fallido')->count(),
            'coveragePercent' => $yearStart->greaterThan($limit) ? 0 : (int) round(($diasCubiertos / $totalDias) * 100),
        ];
    }

    public function coveragePrevYear(): void
    {
        $this->coverageYear--;
    }

    public function coverageNextYear(): void
    {
        $this->coverageYear = min($this->coverageYear + 1, (int) now()->year);
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

    /**
     * A diferencia de Ventas (donde "parcial" es por venta individual
     * fallida dentro de un día ya extraído), en Kardex un local falla o se
     * completa como bloque entero para todo el rango de la corrida -- no hay
     * señal de qué día específico dentro del local falló. Así que "full" es
     * el local completado cuyo rango cubre ese día, "partial" es el local
     * fallido cuyo rango lo cubre (y ninguna corrida posterior lo reparó).
     *
     * @return array<string, string> 'Y-m-d' => 'full'|'partial'
     */
    protected function coverageForLocal(string $localId, int $year): array
    {
        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($year, 12, 31)->endOfDay();
        $coverage = [];

        $locales = KardexExtraccionLocal::query()
            ->where('local_id', $localId)
            ->whereIn('estado', ['completado', 'fallido'])
            ->with('extraccion')
            ->get()
            ->filter(function (KardexExtraccionLocal $local) use ($yearStart, $yearEnd): bool {
                $filtros = $local->extraccion?->filtros ?? [];

                if (empty($filtros['fechaInicio']) || empty($filtros['fechaFin'])) {
                    return false;
                }

                $start = Carbon::parse($filtros['fechaInicio'])->startOfDay()->max($yearStart);
                $end = Carbon::parse($filtros['fechaFin'])->startOfDay()->min($yearEnd);

                return $start->lessThanOrEqualTo($end);
            })
            ->sortBy(fn (KardexExtraccionLocal $local): int => $local->extraccion_id)
            ->values();

        if ($locales->isEmpty()) {
            return [];
        }

        $locales->each(function (KardexExtraccionLocal $local) use ($yearStart, $yearEnd, &$coverage): void {
            $filtros = $local->extraccion->filtros;
            $start = Carbon::parse($filtros['fechaInicio'])->startOfDay()->max($yearStart);
            $end = Carbon::parse($filtros['fechaFin'])->startOfDay()->min($yearEnd);
            $estado = $local->estado === 'completado' ? 'full' : 'partial';

            foreach (CarbonPeriod::create($start, $end) as $day) {
                $key = $day->toDateString();

                // Una corrida posterior que completa sin fallas para este
                // local/día repara el indicador de una corrida anterior.
                if (($coverage[$key] ?? null) !== 'full') {
                    $coverage[$key] = $estado;
                }
            }
        });

        return $coverage;
    }

    private function gateway(): KardexGatewayClient
    {
        return app(KardexGatewayClient::class);
    }

    private function friendlyError(Throwable $exception): string
    {
        Log::error('[Kardex extracción] '.$exception->getMessage(), ['exception' => $exception]);

        if (str_contains($exception->getMessage(), 'cURL error 7') || str_contains($exception->getMessage(), 'Connection refused')) {
            return 'No se pudo conectar con el servicio de Kardex.';
        }

        return $exception->getMessage();
    }
}
