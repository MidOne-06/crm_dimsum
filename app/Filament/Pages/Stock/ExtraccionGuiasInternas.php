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
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
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

        // La pantalla de extracción debe abrir aun cuando Restaurant esté lento
        // o temporalmente caído. Los locales ya sincronizados son suficientes
        // para iniciar una corrida y se restringen nuevamente al ejecutar.
        $this->locals = $this->scopeLocalsToUser(
            GuiaInterna::query()->whereNotNull('local_origen_id')->whereNotNull('local_origen')
                ->select('local_origen_id as id', 'local_origen as name')->distinct()
                ->orderBy('local_origen')->get()->map(fn (GuiaInterna $guia): array => ['id' => (string) $guia->id, 'name' => $guia->name])->all()
        );

        if ($this->locals === []) {
            $this->resultError = 'Aún no hay locales respaldados. Ejecuta la primera sincronización programada.';
        }

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
            Section::make('Locales')->compact()->collapsible()->collapsed()->schema([
                CheckboxList::make('selectedLocals')->hiddenLabel()
                    ->options(fn (): array => collect($this->locals)->pluck('name', 'id')->all())
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 3, 'xl' => 4])
                    ->bulkToggleable()->searchable()->required(),
            ]),
        ])->statePath('data');
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

        $run = app(GuiasInternasHistoricoService::class)->iniciar($start, $end, $locals, auth()->id());
        $args = ['php', 'artisan', 'guias-internas:sincronizar', '--sync-id='.$run->id];
        foreach ($locals as $local) $args[] = '--locales='.$local;
        Process::path(base_path())->start($args);
        $this->extraccionActualId = $run->id;
        $this->esperandoExtraccion = false;
        Notification::make()->title('Extracción iniciada')->success()->send();
    }

    public function refreshExtraccion(): void
    {
        $actual = $this->extraccionActual();
        if ($actual && ! in_array($actual->estado, ['pendiente', 'en_progreso'], true)) {
            $this->esperandoExtraccion = false;
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
