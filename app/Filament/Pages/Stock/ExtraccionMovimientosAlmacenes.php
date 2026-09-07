<?php

namespace App\Filament\Pages\Stock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Models\MovimientoAlmacenHistorico;
use App\Models\MovimientoAlmacenDetalle;
use App\Models\MovimientoAlmacenSincronizacion;
use App\Services\MovimientosAlmacenesGatewayClient;
use App\Services\MovimientosAlmacenesHistoricoService;
use Carbon\CarbonPeriod;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Throwable;

class ExtraccionMovimientosAlmacenes extends Page implements HasTable
{
    use InteractsWithTable;
    use ScopesLocalsToUser;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-tray';
    protected static ?string $navigationLabel = 'Extracción';
    protected static ?string $title = 'Extracción de movimientos entre almacenes';
    protected static string|\UnitEnum|null $navigationGroup = 'Movimientos entre almacenes';
    protected static ?int $navigationSort = 11;
    protected static ?string $slug = 'movimientos-almacenes/extraccion';
    protected string $view = 'filament.pages.stock.extraccion-movimientos-almacenes';

    /** @var array<int, array{id:string,name:string}> */
    public array $locals = [];
    public array $data = [];
    public ?string $resultError = null;
    public ?int $extraccionActualId = null;
    public string $coverageLocalId = '';
    // Los snapshots Livewire creados antes de incorporar la cobertura no
    // contienen estos campos. Valores centinela evitan un Typed property error
    // mientras se hidrata dicho snapshot y se normalizan antes de renderizar.
    public int $coverageYear = 0;
    public int $coverageMonth = 0;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('movimientos-almacenes.extraccion');
    }

    public function mount(): void
    {
        $this->initializeCoveragePeriod();
        $this->cargarLocales();
        $this->data = [
            'selectedLocals' => array_column($this->locals, 'id'),
            'dateStart' => now()->subDays(30)->toDateString(),
            'dateEnd' => now()->toDateString(),
            'estado' => '-1',
            'estadoRecepcion' => '-1',
        ];
        $this->extraccionActualId = MovimientoAlmacenSincronizacion::query()->latest('id')->value('id');
        $this->coverageLocalId = (string) ($this->locals[0]['id'] ?? '');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'md' => 2, 'xl' => 4])->schema([
                DatePicker::make('dateStart')->label('Desde')->native(false)->required(),
                DatePicker::make('dateEnd')->label('Hasta')->native(false)->required(),
                Select::make('estado')->label('Estado')->options(['-1' => 'Todos', '1' => 'Activo', '0' => 'Anulado'])->native(),
                Select::make('estadoRecepcion')->label('Estado de recepción')->options(['-1' => 'Todos', '1' => 'Pendiente', '2' => 'Recepcionado'])->native(),
                CheckboxList::make('selectedLocals')->label('Locales a extraer')
                    ->options(fn (): array => collect($this->locals)->pluck('name', 'id')->all())
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 3])->bulkToggleable()->searchable()->required()->columnSpanFull(),
            ]),
        ])->statePath('data');
    }

    public function abrirFiltrosExtraccion(): void
    {
        $selected = array_map('strval', (array) ($this->data['selectedLocals'] ?? []));
        $this->cargarLocales();
        $this->data['selectedLocals'] = array_values(array_intersect($selected, array_column($this->locals, 'id')));
        $this->dispatch('open-modal', id: 'filtros-extraccion-movimientos');
    }

    public function cerrarFiltrosExtraccion(): void
    {
        $this->dispatch('close-modal', id: 'filtros-extraccion-movimientos');
    }

    private function cargarLocales(): void
    {
        try {
            $this->locals = collect($this->scopeLocalsToUser(app(MovimientosAlmacenesGatewayClient::class)->locales()))
                ->map(fn (array $local): array => ['id' => (string) ($local['id'] ?? ''), 'name' => (string) ($local['name'] ?? '')])
                ->filter(fn (array $local): bool => $local['id'] !== '' && $local['name'] !== '')
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
            $this->resultError = null;
        } catch (Throwable) {
            $this->locals = [];
            $this->resultError = 'No se pudieron cargar los locales desde Restaurant. Intenta nuevamente.';
        }
    }

    public function iniciarExtraccion(): void
    {
        $this->resultError = null;
        $start = (string) ($this->data['dateStart'] ?? '');
        $end = (string) ($this->data['dateEnd'] ?? '');
        $locals = $this->restrictLocalIdsToUser(array_map('strval', (array) ($this->data['selectedLocals'] ?? [])));
        if ($start === '' || $end === '' || $end < $start) { $this->resultError = 'El rango de fechas no es válido.'; return; }
        if ($locals === []) { $this->resultError = 'Selecciona al menos un local.'; return; }

        $estado = in_array((string) ($this->data['estado'] ?? '-1'), ['-1', '0', '1'], true) ? (string) $this->data['estado'] : '-1';
        $estadoRecepcion = in_array((string) ($this->data['estadoRecepcion'] ?? '-1'), ['-1', '1', '2'], true) ? (string) $this->data['estadoRecepcion'] : '-1';
        $run = app(MovimientosAlmacenesHistoricoService::class)->iniciar($start, $end, $locals, $estado, $estadoRecepcion, auth()->id());
        $this->extraccionActualId = $run->id;
        $this->cerrarFiltrosExtraccion();
        $queued = $this->extraccionesActivas()->count();
        Notification::make()->title('Extracción encolada')->body($queued > 1 ? "Hay {$queued} extracciones en cola; esta arrancará cuando le toque su turno." : 'Arranca en menos de un minuto.')->success()->send();
    }

    /** @return \Illuminate\Support\Collection<int, MovimientoAlmacenSincronizacion> */
    public function extraccionesActivas(): \Illuminate\Support\Collection
    {
        return MovimientoAlmacenSincronizacion::query()->whereIn('estado', ['pendiente', 'en_progreso'])->orderByDesc('id')->get();
    }

    public function estaEstancada(MovimientoAlmacenSincronizacion $run): bool
    {
        return in_array($run->estado, ['pendiente', 'en_progreso'], true) && $run->updated_at?->lt(now()->subMinutes(15));
    }

    public function eliminarDeCola(int $id): void
    {
        $run = MovimientoAlmacenSincronizacion::query()->whereKey($id)->where('estado', 'pendiente')->first();
        if (! $run) { Notification::make()->title('Ya no se puede eliminar')->warning()->send(); return; }
        $run->delete();
        Notification::make()->title('Extracción eliminada de la cola')->success()->send();
    }

    public function cancelarExtraccion(int $id): void
    {
        $run = MovimientoAlmacenSincronizacion::query()->whereKey($id)->whereIn('estado', ['pendiente', 'en_progreso'])->first();
        if (! $run) return;
        $run->update(['estado' => 'cancelado', 'mensaje_error' => 'Cancelado manualmente desde la UI. El avance guardado se conserva.', 'completado_en' => now()]);
        Notification::make()->title('Extracción cancelada')->body('El avance guardado se conserva y puede reanudarse.')->warning()->send();
    }

    public function refreshExtraccion(): void
    {
        $this->resetTable();
    }

    public function resumenGeneral(): array
    {
        $this->initializeCoveragePeriod();

        return [
            'movimientos' => MovimientoAlmacenHistorico::count(),
            'detalles' => MovimientoAlmacenDetalle::count(),
            'corridas' => MovimientoAlmacenSincronizacion::count(),
            'fallidas' => MovimientoAlmacenSincronizacion::where('estado', 'fallido')->count(),
            'coveragePercent' => $this->coveragePercent(),
        ];
    }

    public function coveragePrevYear(): void
    {
        $this->initializeCoveragePeriod();
        $this->coverageYear--;
    }

    public function coverageNextYear(): void
    {
        $this->initializeCoveragePeriod();
        $this->coverageYear++;
    }

    public function coveragePrevMonth(): void
    {
        $this->initializeCoveragePeriod();
        $anchor = Carbon::create($this->coverageYear, $this->coverageMonth, 1)->subMonthNoOverflow();
        $this->coverageYear = $anchor->year;
        $this->coverageMonth = $anchor->month;
    }

    public function coverageNextMonth(): void
    {
        $this->initializeCoveragePeriod();
        $anchor = Carbon::create($this->coverageYear, $this->coverageMonth, 1)->addMonthNoOverflow();
        $this->coverageYear = $anchor->year;
        $this->coverageMonth = $anchor->month;
    }

    /** @return array<string, array<string, 'full'|'partial'>> */
    protected function coverageMatrix(): array
    {
        $this->initializeCoveragePeriod();
        $monthStart = Carbon::create($this->coverageYear, $this->coverageMonth, 1)->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth()->startOfDay();
        $matrix = [];

        foreach ($this->locals as $local) {
            $matrix[(string) $local['id']] = [];
        }

        MovimientoAlmacenSincronizacion::query()
            ->whereIn('estado', ['completado', 'completado_con_errores'])
            ->get()
            ->each(function (MovimientoAlmacenSincronizacion $run) use (&$matrix, $monthStart, $monthEnd): void {
                if (! $run->fecha_inicio || ! $run->fecha_fin || $run->fecha_inicio->gt($monthEnd) || $run->fecha_fin->lt($monthStart)) {
                    return;
                }

                $runLocales = array_map('strval', $run->filtros['locales'] ?? []);
                $periodStart = $run->fecha_inicio->copy()->max($monthStart);
                $periodEnd = $run->fecha_fin->copy()->min($monthEnd);
                $status = $this->coverageStatus($run);

                foreach ($this->locals as $local) {
                    $id = (string) $local['id'];
                    $name = (string) ($local['name'] ?? '');
                    $applies = $runLocales === [] || in_array($id, $runLocales, true) || in_array($name, $runLocales, true);

                    if (! $applies) {
                        continue;
                    }

                    foreach (CarbonPeriod::create($periodStart, $periodEnd) as $day) {
                        $key = $day->toDateString();
                        if (($matrix[$id][$key] ?? null) !== 'full') {
                            $matrix[$id][$key] = $status;
                        }
                    }
                }
            });

        return $matrix;
    }

    /** @return array{total: int, conProblemas: \Illuminate\Support\Collection} */
    public function coverageSummary(): array
    {
        $this->initializeCoveragePeriod();
        $matrix = $this->coverageMatrix();
        $monthStart = Carbon::create($this->coverageYear, $this->coverageMonth, 1)->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth()->startOfDay()->min(now()->startOfDay());
        $daysUntilToday = max(1, (int) $monthStart->diffInDays($monthEnd) + 1);

        $items = collect($this->locals)->map(function (array $local) use ($matrix, $monthStart, $monthEnd, $daysUntilToday): array {
            $row = $matrix[(string) $local['id']] ?? [];
            $full = $partial = $missing = 0;

            foreach (CarbonPeriod::create($monthStart, $monthEnd) as $day) {
                match ($row[$day->toDateString()] ?? null) {
                    'full' => $full++,
                    'partial' => $partial++,
                    default => $missing++,
                };
            }

            return [
                'id' => (string) $local['id'],
                'name' => (string) $local['name'],
                'partial' => $partial,
                'missing' => $missing,
                'pct' => (int) round(($full / $daysUntilToday) * 100),
            ];
        });

        return [
            'total' => $items->count(),
            'conProblemas' => $items->filter(fn (array $item): bool => $item['pct'] < 100)->sortBy('pct')->values(),
        ];
    }

    public function coverageMap(): array
    {
        $this->initializeCoveragePeriod();
        if ($this->coverageLocalId === '') {
            return [];
        }

        $yearStart = Carbon::create($this->coverageYear, 1, 1);
        $yearEnd = Carbon::create($this->coverageYear, 12, 31);
        $coverage = [];

        MovimientoAlmacenSincronizacion::query()
            ->whereIn('estado', ['completado', 'completado_con_errores'])
            ->get()
            ->filter(function (MovimientoAlmacenSincronizacion $run) use ($yearStart, $yearEnd): bool {
                $runLocales = array_map('strval', $run->filtros['locales'] ?? []);

                return $run->fecha_inicio
                    && $run->fecha_fin
                    && $run->fecha_inicio->lte($yearEnd)
                    && $run->fecha_fin->gte($yearStart)
                    && ($runLocales === [] || in_array($this->coverageLocalId, $runLocales, true));
            })
            ->each(function (MovimientoAlmacenSincronizacion $run) use (&$coverage, $yearStart, $yearEnd): void {
                foreach (CarbonPeriod::create($run->fecha_inicio->copy()->max($yearStart), $run->fecha_fin->copy()->min($yearEnd)) as $day) {
                    $key = $day->toDateString();
                    if (($coverage[$key] ?? null) !== 'full') {
                        $coverage[$key] = $this->coverageStatus($run);
                    }
                }
            });

        return $coverage;
    }

    public function coverageGaps(): array
    {
        $this->initializeCoveragePeriod();
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

    /**
     * Una corrida limitada a un estado o a una recepción no representa todos
     * los movimientos de ese día. Se muestra como parcial aunque Restaurant
     * haya respondido sin errores, para no ocultar un hueco de cobertura.
     */
    private function coverageStatus(MovimientoAlmacenSincronizacion $run): string
    {
        $filters = (array) $run->filtros;
        $allStates = (string) ($filters['estado'] ?? '-1') === '-1';
        $allReceipts = (string) ($filters['estado_recepcion'] ?? '-1') === '-1';

        return $run->errores === 0 && $allStates && $allReceipts ? 'full' : 'partial';
    }

    private function coveragePercent(): int
    {
        $this->initializeCoveragePeriod();
        $start = Carbon::create($this->coverageYear, 1, 1);
        $end = Carbon::create($this->coverageYear, 12, 31)->min(now());

        $daysCovered = collect($this->coverageMap())
            ->filter(fn (string $status): bool => $status === 'full')
            ->count();

        return (int) round(($daysCovered / max(1, $start->diffInDays($end) + 1)) * 100);
    }

    private function initializeCoveragePeriod(): void
    {
        if ($this->coverageYear < 2000) {
            $this->coverageYear = (int) now()->year;
        }

        if ($this->coverageMonth < 1 || $this->coverageMonth > 12) {
            $this->coverageMonth = (int) now()->month;
        }
    }

    public function table(Table $table): Table
    {
        return $table->query(MovimientoAlmacenSincronizacion::query()->latest('id'))->columns([
            TextColumn::make('id')->label('Cód.')->sortable(),
            TextColumn::make('rango')->label('Rango')->state(fn (MovimientoAlmacenSincronizacion $r): string => $r->fecha_inicio?->format('d/m/Y').' al '.$r->fecha_fin?->format('d/m/Y'))->wrap(),
            TextColumn::make('estado')->label('Estado')->formatStateUsing(fn (?string $s): string => ucfirst(str_replace('_', ' ', (string) $s)))->badge(),
            TextColumn::make('cabeceras_guardadas')->label('Movimientos')->numeric()->alignEnd(),
            TextColumn::make('detalles_guardados')->label('Detalles')->numeric()->alignEnd(),
            TextColumn::make('cabeceras_eliminadas')->label('Eliminados')->numeric()->alignEnd(),
            TextColumn::make('errores')->label('Fallidas')->numeric()->alignEnd()->color(fn ($s): string => (int) $s > 0 ? 'danger' : 'gray'),
            TextColumn::make('iniciado_en')->label('Iniciado')->dateTime('d/m/Y H:i')->sortable(),
        ])->paginated([10, 25, 50, 100])->defaultPaginationPageOption(10)->emptyStateHeading('Sin extracciones registradas.');
    }
}
