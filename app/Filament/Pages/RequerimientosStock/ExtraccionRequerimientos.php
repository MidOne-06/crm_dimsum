<?php

namespace App\Filament\Pages\RequerimientosStock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Models\RequerimientoStockHistorico;
use App\Models\RequerimientoStockSincronizacion;
use App\Services\RequerimientoStockGatewayClient;
use Filament\Forms\Components\CheckboxList;
use Filament\Schemas\Components\Grid;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Carbon\CarbonPeriod;
use Throwable;

/**
 * Submódulo de extracción de Requerimientos de Stock -- calcado de
 * Stock\ExtraccionGuiasInternas: filtro explícito + botón "Iniciar" +
 * progreso en vivo + "Detener" + historial. Reemplaza el sincronizado
 * implícito que antes vivía en ReporteRequerimientos (auto-sync en cada
 * búsqueda), que quedaba "pegado" en 0% porque el proceso se lanzaba desde
 * un worker de PHP-FPM que no lo dejaba sobrevivir. Esta página solo
 * ENCOLA la fila; el arranque real lo hace
 * DespacharSincronizacionesPendientes desde el scheduler.
 */
class ExtraccionRequerimientos extends Page implements HasTable
{
    use InteractsWithTable;
    use ScopesLocalsToUser;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-tray';
    protected static ?string $navigationLabel = 'Extracción';
    protected static ?string $title = 'Extracción de requerimientos';
    protected static string|\UnitEnum|null $navigationGroup = 'Requerimientos de Stock';
    protected static ?int $navigationSort = 6;
    protected static ?string $slug = 'requerimientos-stock/extraccion';
    protected string $view = 'filament.pages.requerimientos-stock.extraccion-requerimientos';

    public array $locals = [];
    public ?array $data = [];
    public ?string $resultError = null;
    public ?int $extraccionActualId = null;
    public bool $esperandoExtraccion = false;
    public string $activeDatePreset = 'last30';
    public string $coverageLocalId = '';
    public int $coverageYear;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('requerimientos-stock.reporte.sincronizar');
    }

    public function mount(): void
    {
        $this->coverageYear = (int) now()->year;

        try {
            $this->locals = $this->scopeLocalsToUser(
                collect(app(RequerimientoStockGatewayClient::class)->locals())
                    ->map(fn (array $local): array => ['id' => (string) $local['id'], 'name' => (string) $local['name']])
                    ->all()
            );
        } catch (Throwable) {
            // La pantalla debe abrir aun con Restaurant lento/caído; se usan los locales ya vistos en el histórico local.
            $this->locals = $this->scopeLocalsToUser(
                RequerimientoStockHistorico::query()->whereNotNull('solicitado_por')->distinct()
                    ->orderBy('solicitado_por')->pluck('solicitado_por')
                    ->map(fn (string $nombre): array => ['id' => $nombre, 'name' => $nombre])->all()
            );
        }

        if ($this->locals === []) {
            $this->resultError = 'Aún no hay locales respaldados. Ejecuta la primera sincronización programada.';
        }

        $this->data = [
            'selectedLocals' => array_column($this->locals, 'id'),
            'dateStart' => now()->subDays(30)->toDateString(),
            'dateEnd' => now()->toDateString(),
        ];
        $this->extraccionActualId = RequerimientoStockSincronizacion::query()->latest('id')->value('id');
        $this->coverageLocalId = (string) ($this->locals[0]['id'] ?? '');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'md' => 2, 'xl' => 4])->schema([
                CheckboxList::make('selectedLocals')->label('Locales a extraer')
                    ->options(fn (): array => collect($this->locals)->pluck('name', 'id')->all())
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 3, 'xl' => 4])
                    ->bulkToggleable()->searchable()->required()->columnSpanFull(),
            ]),
        ])->statePath('data');
    }

    public function abrirFiltrosExtraccion(): void
    {
        $this->dispatch('open-modal', id: 'filtros-extraccion-requerimientos');
    }

    public function cerrarFiltrosExtraccion(): void
    {
        $this->dispatch('close-modal', id: 'filtros-extraccion-requerimientos');
    }

    public function syncDateRange(string $start, string $end, string $preset = 'custom'): void
    {
        $this->data['dateStart'] = $start;
        $this->data['dateEnd'] = $end;
        $this->activeDatePreset = $preset;
    }

    public function hayExtraccionEnProgreso(): bool
    {
        return RequerimientoStockSincronizacion::query()->where('estado', 'en_progreso')->exists();
    }

    /**
     * Todas las corridas pendientes o en progreso, más antigua primero --
     * el mismo orden en que DespacharSincronizacionesPendientes las va a
     * tomar (::oldest('id')->first()). Reemplaza a "la corrida más
     * reciente" (extraccionActual()) para que encolar varias no oculte las
     * que ya estaban esperando su turno.
     *
     * @return \Illuminate\Support\Collection<int, RequerimientoStockSincronizacion>
     */
    public function extraccionesActivas(): \Illuminate\Support\Collection
    {
        return RequerimientoStockSincronizacion::query()
            ->whereIn('estado', ['pendiente', 'en_progreso'])
            ->oldest('id')
            ->get();
    }

    public function iniciarExtraccion(): void
    {
        $this->resultError = null;
        $start = (string) ($this->data['dateStart'] ?? '');
        $end = (string) ($this->data['dateEnd'] ?? '');
        $locals = $this->restrictLocalIdsToUser($this->data['selectedLocals'] ?? []);

        if ($start === '' || $end === '' || $end < $start) {
            $this->resultError = 'El rango de fechas no es válido.';

            return;
        }
        if ($locals === []) {
            $this->resultError = 'Selecciona al menos un local.';

            return;
        }

        // Solo encola: el arranque real lo hace el despachador programado
        // (ver DespacharSincronizacionesPendientes) -- un fork lanzado
        // directo desde este worker web no sobrevive en producción. Se
        // permite encolar aunque ya haya otra en curso: el despachador solo
        // toma una a la vez (la más vieja primero), así que esta espera su
        // turno en vez de bloquear al usuario con el botón deshabilitado.
        $run = RequerimientoStockSincronizacion::create([
            'filtros' => [
                'fecha_inicio' => $start, 'fecha_fin' => $end,
                'locales' => $locals, 'locales_produccion' => [],
                'estado' => '-1', 'codigo' => '', 'encargado' => '', 'por_fecha' => '0', 'items' => [],
            ],
            'estado' => 'pendiente',
            'iniciado_por' => auth()->id(),
        ]);
        $this->extraccionActualId = $run->id;
        $this->esperandoExtraccion = true;
        $this->cerrarFiltrosExtraccion();
        $enCola = $this->extraccionesActivas()->count();
        Notification::make()
            ->title('Extracción encolada')
            ->body($enCola > 1 ? "Hay {$enCola} extracciones en cola; esta arrancará cuando le toque su turno." : 'Arranca en menos de un minuto.')
            ->success()->send();
    }

    public function refreshExtraccion(): void
    {
        if ($this->extraccionesActivas()->isEmpty()) {
            $this->esperandoExtraccion = false;
        }
    }

    public function extraccionActual(): ?RequerimientoStockSincronizacion
    {
        return $this->extraccionActualId ? RequerimientoStockSincronizacion::find($this->extraccionActualId) : null;
    }

    /**
     * Elimina una corrida que todavía no arrancó (estado 'pendiente') --
     * el pedido explícito de poder sacar algo de la cola antes de que le
     * toque el turno, sin tener que esperar a que arranque para recién ahí
     * poder "Detener"la.
     */
    public function eliminarDeCola(int $id): void
    {
        if (! auth()->user()?->hasPermission('requerimientos-stock.reporte.sincronizar')) {
            Notification::make()->title('No tienes permiso para modificar la cola.')->danger()->send();

            return;
        }

        $run = RequerimientoStockSincronizacion::whereKey($id)->where('estado', 'pendiente')->first();
        if (! $run) {
            // Puede haber arrancado justo antes del clic (el despachador
            // corre cada minuto) -- no es un error, solo ya no aplica.
            Notification::make()->title('Ya no se puede eliminar')->body('Esta extracción ya arrancó o dejó de existir.')->warning()->send();

            return;
        }

        $run->delete();
        Notification::make()->title('Extracción eliminada de la cola')->success()->send();
    }

    /** Detiene una extracción en curso: coopera con el chequeo entre páginas/registros y conserva el avance ya guardado. */
    public function cancelarExtraccion(int $id): void
    {
        if (! auth()->user()?->hasPermission('requerimientos-stock.reporte.sincronizar')) {
            Notification::make()->title('No tienes permiso para cancelar la extracción.')->danger()->send();

            return;
        }

        $actual = RequerimientoStockSincronizacion::find($id);
        if (! $actual || ! in_array($actual->estado, ['pendiente', 'en_progreso'], true)) {
            return;
        }

        $matado = $this->matarProceso($actual->proceso_pid);

        $actual->update([
            'estado' => 'cancelado',
            'mensaje_error' => sprintf(
                'Cancelado manualmente desde la UI por %s. Avance conservado: %d/%d requerimientos. Reanudable resetando a pendiente y --sync-id=%d.',
                auth()->user()?->name ?? 'admin',
                $actual->registros_procesados,
                $actual->total_registros ?: 0,
                $actual->id,
            ),
            'completado_en' => now(),
        ]);

        Notification::make()
            ->title('Extracción cancelada')
            ->body($matado ? 'El proceso se detuvo correctamente.' : 'Se marcó como cancelada; el proceso ya no estaba activo.')
            ->warning()->send();
    }

    /** Mata el proceso OS por PID. Soporta Linux (contenedor de producción) y Windows (desarrollo local). */
    private function matarProceso(?int $pid): bool
    {
        if (! $pid) {
            return false;
        }

        try {
            if (function_exists('posix_kill')) {
                if (! posix_kill($pid, 0)) {
                    return false;
                }

                return posix_kill($pid, 9);
            }

            exec('taskkill /F /PID '.escapeshellarg((string) $pid).' 2>&1', $salida, $codigo);

            return $codigo === 0;
        } catch (Throwable) {
            return false;
        }
    }

    public function table(Table $table): Table
    {
        return $table->query(RequerimientoStockSincronizacion::query()->latest('id'))->columns([
            TextColumn::make('id')->label('Cód.')->sortable(),
            TextColumn::make('rango')->label('Rango')->state(function (RequerimientoStockSincronizacion $r): string {
                $filtros = (array) $r->filtros;

                return ($filtros['fecha_inicio'] ?? '—').' al '.($filtros['fecha_fin'] ?? '—');
            })->wrap(),
            TextColumn::make('estado')->label('Estado')->formatStateUsing(fn (?string $s): string => ucfirst(str_replace('_', ' ', (string) $s)))->badge(),
            TextColumn::make('cabeceras_guardadas')->label('Requerimientos')->numeric()->alignEnd(),
            TextColumn::make('detalles_guardados')->label('Detalles')->numeric()->alignEnd(),
            TextColumn::make('errores')->label('Fallidos')->numeric()->alignEnd()->color(fn ($s): string => (int) $s > 0 ? 'danger' : 'gray'),
            TextColumn::make('iniciado_en')->label('Iniciado')->dateTime('d/m/Y H:i')->sortable(),
        ])->paginated([10, 25, 50, 100])->defaultPaginationPageOption(10)->emptyStateHeading('Sin extracciones registradas.');
    }

    public function resumenGeneral(): array
    {
        return [
            'requerimientos' => RequerimientoStockHistorico::count(),
            'corridas' => RequerimientoStockSincronizacion::count(),
            'fallidas' => RequerimientoStockSincronizacion::where('estado', 'fallido')->count(),
            'coveragePercent' => $this->coveragePercent(),
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

    /**
     * Devuelve el estado por día del año seleccionado para el local elegido.
     * La cobertura se deriva de las corridas registradas, no de una suposición
     * basada únicamente en cabeceras: verde = corrida sin fallos, ámbar = corrida
     * con fallos y vacío = rango aún no extraído.
     *
     * @return array<string, 'full'|'partial'>
     */
    public function coverageMap(): array
    {
        if ($this->coverageLocalId === '') {
            return [];
        }

        $yearStart = Carbon::create($this->coverageYear, 1, 1);
        $yearEnd = Carbon::create($this->coverageYear, 12, 31);
        $coverage = [];

        RequerimientoStockSincronizacion::query()
            ->whereIn('estado', ['completado', 'completado_con_errores'])
            ->get()
            ->filter(function (RequerimientoStockSincronizacion $run) use ($yearStart, $yearEnd): bool {
                $filters = (array) $run->filtros;
                $start = isset($filters['fecha_inicio']) ? Carbon::parse($filters['fecha_inicio']) : null;
                $end = isset($filters['fecha_fin']) ? Carbon::parse($filters['fecha_fin']) : null;
                $locals = array_map('strval', $filters['locales'] ?? []);

                return $start && $end
                    && $start->lte($yearEnd)
                    && $end->gte($yearStart)
                    && ($locals === [] || in_array($this->coverageLocalId, $locals, true));
            })
            ->each(function (RequerimientoStockSincronizacion $run) use (&$coverage, $yearStart, $yearEnd): void {
                $filters = (array) $run->filtros;
                $start = Carbon::parse($filters['fecha_inicio'])->max($yearStart);
                $end = Carbon::parse($filters['fecha_fin'])->min($yearEnd);

                foreach (CarbonPeriod::create($start, $end) as $day) {
                    $key = $day->toDateString();
                    if (($coverage[$key] ?? null) !== 'full') {
                        $coverage[$key] = $run->errores > 0 ? 'partial' : 'full';
                    }
                }
            });

        return $coverage;
    }

    /** @return array<int, array{start: string, end: string}> */
    public function coverageGaps(): array
    {
        $map = $this->coverageMap();
        $start = Carbon::create($this->coverageYear, 1, 1);
        $end = Carbon::create($this->coverageYear, 12, 31)->min(now());
        $gaps = [];
        $gapStart = null;

        foreach (CarbonPeriod::create($start, $end) as $day) {
            if (! isset($map[$day->toDateString()]) && $gapStart === null) {
                $gapStart = $day->copy();
            }

            if (isset($map[$day->toDateString()]) && $gapStart !== null) {
                $gaps[] = ['start' => $gapStart->toDateString(), 'end' => $day->copy()->subDay()->toDateString()];
                $gapStart = null;
            }
        }

        if ($gapStart !== null) {
            $gaps[] = ['start' => $gapStart->toDateString(), 'end' => $end->toDateString()];
        }

        return $gaps;
    }

    private function coveragePercent(): int
    {
        $start = Carbon::create($this->coverageYear, 1, 1);
        $end = Carbon::create($this->coverageYear, 12, 31)->min(now());

        return (int) round((collect($this->coverageMap())->where('full')->count() / max(1, $start->diffInDays($end) + 1)) * 100);
    }
}
