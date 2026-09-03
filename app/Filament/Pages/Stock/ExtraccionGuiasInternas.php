<?php

namespace App\Filament\Pages\Stock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Models\GuiaInterna;
use App\Models\GuiaInternaDetalle;
use App\Models\GuiaInternaSincronizacion;
use App\Services\GuiasInternasGatewayClient;
use App\Services\GuiasInternasHistoricoService;
use Carbon\CarbonPeriod;
use Filament\Forms\Components\CheckboxList;
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


class ExtraccionGuiasInternas extends Page implements HasTable
{
    use InteractsWithTable;
    use ScopesLocalsToUser;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-tray';
    protected static ?string $navigationLabel = 'Extracción';
    protected static ?string $title = 'Extracción de guías internas';
    protected static string|\UnitEnum|null $navigationGroup = 'Guías internas';
    protected static ?int $navigationSort = 11;
    protected static ?string $slug = 'guias-internas/extraccion';
    protected string $view = 'filament.pages.stock.extraccion-guias-internas';

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
        return (bool) auth()->user()?->hasPermission('guias-internas.sincronizar');
    }

    public function mount(): void
    {
        $this->coverageYear = (int) now()->year;

        $this->cargarLocalesRestaurant();

        $this->data = [
            'selectedLocals' => array_column($this->locals, 'id'),
            'dateStart' => now()->subDays(30)->toDateString(),
            'dateEnd' => now()->toDateString(),
        ];
        $this->extraccionActualId = GuiaInternaSincronizacion::query()->latest('id')->value('id');
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
        $seleccionados = array_map('strval', $this->data['selectedLocals'] ?? []);
        $this->cargarLocalesRestaurant();

        if ($this->locals !== []) {
            $localesVigentes = array_column($this->locals, 'id');
            $this->data['selectedLocals'] = array_values(array_intersect($seleccionados, $localesVigentes));
        }

        $this->dispatch('open-modal', id: 'filtros-extraccion-guias');
    }

    public function cerrarFiltrosExtraccion(): void
    {
        $this->dispatch('close-modal', id: 'filtros-extraccion-guias');
    }

    private function cargarLocalesRestaurant(): void
    {
        try {
            $this->locals = collect($this->scopeLocalsToUser(app(GuiasInternasGatewayClient::class)->locales()))
                ->map(fn (array $local): array => [
                    'id' => (string) ($local['id'] ?? ''),
                    'name' => (string) ($local['name'] ?? ''),
                ])
                ->filter(fn (array $local): bool => $local['id'] !== '' && $local['name'] !== '')
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all();
            $this->resultError = null;
        } catch (Throwable) {
            $this->locals = [];
            $this->resultError = 'No se pudieron cargar los locales desde Restaurant. Intenta nuevamente.';
        }
    }

    public function syncDateRange(string $start, string $end, string $preset = 'custom'): void
    {
        $this->data['dateStart'] = $start;
        $this->data['dateEnd'] = $end;
        $this->activeDatePreset = $preset;
    }

    public function hayExtraccionEnProgreso(): bool
    {
        return GuiaInternaSincronizacion::query()->whereIn('estado', ['pendiente', 'en_progreso'])->exists();
    }

    /**
     * TODAS las corridas activas ahora mismo, no solo "la más reciente por
     * id" -- mount() elegía únicamente extraccionActualId = latest('id'),
     * así que si esa corrida ya terminó pero una MÁS ANTIGUA seguía atascada
     * en_progreso, el botón quedaba deshabilitado sin que la pantalla
     * mostrara ningún motivo: no había ninguna tarjeta visible que
     * explicara qué la bloqueaba ni forma de cancelarla desde la UI.
     *
     * @return \Illuminate\Support\Collection<int, GuiaInternaSincronizacion>
     */
    public function extraccionesActivas(): \Illuminate\Support\Collection
    {
        return GuiaInternaSincronizacion::query()->whereIn('estado', ['pendiente', 'en_progreso'])->orderByDesc('id')->get();
    }

    /**
     * Sin ningún avance real en los últimos 15 minutos casi siempre
     * significa que el proceso que la corría ya no existe (contenedor
     * reiniciado, zombie sin reaparentar) y nunca pudo marcarse a sí mismo
     * como 'fallido'. Se usa para distinguir visualmente una corrida
     * genuinamente en curso de una que solo parece estarlo.
     */
    public function estaEstancada(GuiaInternaSincronizacion $run): bool
    {
        return in_array($run->estado, ['pendiente', 'en_progreso'], true) && $run->updated_at->lt(now()->subMinutes(15));
    }

    public function iniciarExtraccion(): void
    {
        $this->resultError = null;
        $start = (string) ($this->data['dateStart'] ?? '');
        $end = (string) ($this->data['dateEnd'] ?? '');
        $locals = $this->restrictLocalIdsToUser($this->data['selectedLocals'] ?? []);

        if ($this->hayExtraccionEnProgreso()) {
            $this->resultError = 'Ya hay una extracción en progreso. Espera a que termine antes de iniciar otra.';
            return;
        }
        if ($start === '' || $end === '' || $end < $start) {
            $this->resultError = 'El rango de fechas no es válido.';
            return;
        }
        if ($locals === []) {
            $this->resultError = 'Selecciona al menos un local.';
            return;
        }

        // OJO: aquí NO se lanza Process::start(). Se comprobó empíricamente
        // (sleep de prueba, en el worker web Y por consola dentro del propio
        // contenedor) que un hijo forkeado con Process::start() no sobrevive
        // en este contenedor bajo NINGÚN padre -- no es un problema de
        // PHP-FPM específicamente. El arranque real lo hace
        // extracciones:despachar-pendientes (programado cada minuto), que
        // usa BackgroundArtisan -- el mecanismo de `&` de shell que sí se
        // comprobó que sobrevive. Esta acción solo encola.
        $run = app(GuiasInternasHistoricoService::class)->iniciar($start, $end, $locals, auth()->id());
        $this->extraccionActualId = $run->id;
        $this->esperandoExtraccion = true;
        $this->cerrarFiltrosExtraccion();
        Notification::make()->title('Extracción encolada')->body('Arranca en menos de un minuto.')->success()->send();
    }

    public function refreshExtraccion(): void
    {
        $actual = $this->extraccionActual();
        if ($actual && ! in_array($actual->estado, ['pendiente', 'en_progreso'], true)) {
            $this->esperandoExtraccion = false;
        }
    }

    /**
     * Detiene una extracción en curso, identificada por id -- cualquiera de
     * las que devuelve extraccionesActivas(), no solo "la más reciente".
     * Antes de esto, la única forma de cancelar una corrida atascada que no
     * fuera la última era acceso directo a consola/servidor para matar el
     * proceso y editar la fila a mano.
     */
    public function cancelarExtraccion(int $id): void
    {
        if (! auth()->user()?->hasPermission('guias-internas.sincronizar')) {
            Notification::make()->title('No tienes permiso para cancelar la extracción.')->danger()->send();

            return;
        }

        $actual = GuiaInternaSincronizacion::find($id);
        if (! $actual || ! in_array($actual->estado, ['pendiente', 'en_progreso'], true)) {
            return;
        }

        $matado = $this->matarProceso($actual->proceso_pid);

        $actual->update([
            'estado' => 'cancelado',
            'mensaje_error' => sprintf(
                'Cancelado manualmente desde la UI por %s. Avance conservado: %d/%d páginas, %d cabeceras. Reanudable con --sync-id=%d.',
                auth()->user()?->name ?? 'admin',
                $actual->paginas_procesadas,
                $actual->paginas_total ?: 0,
                $actual->cabeceras_guardadas,
                $actual->id,
            ),
            'completado_en' => now(),
        ]);

        if ($id === $this->extraccionActualId) $this->esperandoExtraccion = false;
        Notification::make()
            ->title('Extracción cancelada')
            ->body($matado ? 'El proceso se detuvo correctamente.' : 'Se marcó como cancelada; el proceso ya no estaba activo o corre en otro servidor.')
            ->warning()->send();
    }

    /** Mata el proceso OS por PID. Soporta Linux (contenedor de producción) y Windows (desarrollo local). */
    private function matarProceso(?int $pid): bool
    {
        if (! $pid) return false;

        try {
            if (function_exists('posix_kill')) {
                if (! posix_kill($pid, 0)) return false; // ya no existe
                return posix_kill($pid, 9);
            }

            // Windows: no hay posix_*, se usa taskkill. Código 0 = éxito.
            exec('taskkill /F /PID '.escapeshellarg((string) $pid).' 2>&1', $salida, $codigo);

            return $codigo === 0;
        } catch (Throwable) {
            return false;
        }
    }

    public function extraccionActual(): ?GuiaInternaSincronizacion
    {
        return $this->extraccionActualId ? GuiaInternaSincronizacion::find($this->extraccionActualId) : null;
    }

    public function table(Table $table): Table
    {
        return $table->query(GuiaInternaSincronizacion::query()->latest('id'))->columns([
            TextColumn::make('id')->label('Cód.')->sortable(),
            TextColumn::make('rango')->label('Rango')->state(fn (GuiaInternaSincronizacion $r): string => $r->fecha_inicio?->format('d/m/Y').' al '.$r->fecha_fin?->format('d/m/Y'))->wrap(),
            TextColumn::make('estado')->label('Estado')->formatStateUsing(fn (?string $s): string => ucfirst(str_replace('_', ' ', (string) $s)))->badge(),
            TextColumn::make('cabeceras_guardadas')->label('Cabeceras')->numeric()->alignEnd(),
            TextColumn::make('detalles_guardados')->label('Detalles')->numeric()->alignEnd(),
            TextColumn::make('cabeceras_eliminadas')->label('Eliminadas')->numeric()->alignEnd(),
            TextColumn::make('errores')->label('Fallidas')->numeric()->alignEnd()->color(fn ($s): string => (int) $s > 0 ? 'danger' : 'gray'),
            TextColumn::make('iniciado_en')->label('Iniciado')->dateTime('d/m/Y H:i')->sortable(),
        ])->paginated([10, 25, 50, 100])->defaultPaginationPageOption(10)->emptyStateHeading('Sin extracciones registradas.');
    }

    public function resumenGeneral(): array
    {
        return [
            'guias' => GuiaInterna::count(),
            'detalles' => GuiaInternaDetalle::count(),
            'corridas' => GuiaInternaSincronizacion::count(),
            'fallidas' => GuiaInternaSincronizacion::where('estado', 'fallido')->count(),
            'coveragePercent' => $this->coveragePercent(),
        ];
    }

    public function coveragePrevYear(): void { $this->coverageYear--; }
    public function coverageNextYear(): void { $this->coverageYear++; }

    public function coverageMap(): array
    {
        if ($this->coverageLocalId === '') return [];
        $yearStart = Carbon::create($this->coverageYear, 1, 1);
        $yearEnd = Carbon::create($this->coverageYear, 12, 31);
        $coverage = [];
        GuiaInternaSincronizacion::query()->whereIn('estado', ['completado', 'completado_con_errores'])->get()
            ->filter(function (GuiaInternaSincronizacion $run) use ($yearStart, $yearEnd): bool {
                $locals = array_map('strval', $run->filtros['locales'] ?? []);
                return $run->fecha_inicio && $run->fecha_fin && $run->fecha_inicio->lte($yearEnd) && $run->fecha_fin->gte($yearStart) && ($locals === [] || in_array($this->coverageLocalId, $locals, true));
            })->each(function (GuiaInternaSincronizacion $run) use (&$coverage, $yearStart, $yearEnd): void {
                foreach (CarbonPeriod::create($run->fecha_inicio->copy()->max($yearStart), $run->fecha_fin->copy()->min($yearEnd)) as $day) {
                    $key = $day->toDateString();
                    if (($coverage[$key] ?? null) !== 'full') $coverage[$key] = $run->errores > 0 ? 'partial' : 'full';
                }
            });
        return $coverage;
    }

    public function coverageGaps(): array
    {
        $map = $this->coverageMap(); $start = Carbon::create($this->coverageYear, 1, 1); $end = Carbon::create($this->coverageYear, 12, 31)->min(now()); $gaps = []; $gapStart = null;
        foreach (CarbonPeriod::create($start, $end) as $day) {
            if (! isset($map[$day->toDateString()]) && $gapStart === null) $gapStart = $day->copy();
            if (isset($map[$day->toDateString()]) && $gapStart !== null) { $gaps[] = ['start' => $gapStart->toDateString(), 'end' => $day->copy()->subDay()->toDateString()]; $gapStart = null; }
        }
        if ($gapStart !== null) $gaps[] = ['start' => $gapStart->toDateString(), 'end' => $end->toDateString()];
        return $gaps;
    }

    private function coveragePercent(): int
    {
        $start = Carbon::create($this->coverageYear, 1, 1); $end = Carbon::create($this->coverageYear, 12, 31)->min(now());
        return (int) round((collect($this->coverageMap())->where('full')->count() / max(1, $start->diffInDays($end) + 1)) * 100);
    }
}
