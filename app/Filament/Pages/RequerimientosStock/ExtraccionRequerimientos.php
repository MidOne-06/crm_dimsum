<?php

namespace App\Filament\Pages\RequerimientosStock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Models\RequerimientoStockHistorico;
use App\Models\RequerimientoStockSincronizacion;
use App\Services\RequerimientoStockGatewayClient;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
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

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('requerimientos-stock.reporte.sincronizar');
    }

    public function mount(): void
    {
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
        return RequerimientoStockSincronizacion::query()->whereIn('estado', ['pendiente', 'en_progreso'])->exists();
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

        // Solo encola: el arranque real lo hace el despachador programado
        // (ver DespacharSincronizacionesPendientes) -- un fork lanzado
        // directo desde este worker web no sobrevive en producción.
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
        Notification::make()->title('Extracción encolada')->body('Arranca en menos de un minuto.')->success()->send();
    }

    public function refreshExtraccion(): void
    {
        $actual = $this->extraccionActual();
        if ($actual && ! in_array($actual->estado, ['pendiente', 'en_progreso'], true)) {
            $this->esperandoExtraccion = false;
        }
    }

    public function extraccionActual(): ?RequerimientoStockSincronizacion
    {
        return $this->extraccionActualId ? RequerimientoStockSincronizacion::find($this->extraccionActualId) : null;
    }

    /** Detiene una extracción en curso: mata el proceso real y conserva el avance ya guardado. */
    public function cancelarExtraccion(): void
    {
        if (! auth()->user()?->hasPermission('requerimientos-stock.reporte.sincronizar')) {
            Notification::make()->title('No tienes permiso para cancelar la extracción.')->danger()->send();

            return;
        }

        $actual = $this->extraccionActual();
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

        $this->esperandoExtraccion = false;
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
        ];
    }
}
