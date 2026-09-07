<?php

namespace App\Filament\Pages\Stock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Models\MovimientoAlmacenHistorico;
use App\Models\MovimientoAlmacenDetalle;
use App\Models\MovimientoAlmacenSincronizacion;
use App\Services\MovimientosAlmacenesGatewayClient;
use App\Services\MovimientosAlmacenesHistoricoService;
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

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('movimientos-almacenes.extraccion');
    }

    public function mount(): void
    {
        $this->cargarLocales();
        $this->data = [
            'selectedLocals' => array_column($this->locals, 'id'),
            'dateStart' => now()->subDays(30)->toDateString(),
            'dateEnd' => now()->toDateString(),
            'estado' => '-1',
            'estadoRecepcion' => '-1',
        ];
        $this->extraccionActualId = MovimientoAlmacenSincronizacion::query()->latest('id')->value('id');
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
        return [
            'movimientos' => MovimientoAlmacenHistorico::count(),
            'detalles' => MovimientoAlmacenDetalle::count(),
            'corridas' => MovimientoAlmacenSincronizacion::count(),
            'fallidas' => MovimientoAlmacenSincronizacion::where('estado', 'fallido')->count(),
        ];
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
