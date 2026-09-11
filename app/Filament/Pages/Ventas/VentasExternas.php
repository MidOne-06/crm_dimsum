<?php

namespace App\Filament\Pages\Ventas;

use App\Models\CanalVentaExterna;
use App\Models\CuotaVentaExterna;
use App\Models\VentaExternaDiaria;
use App\Services\VentasExternasService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class VentasExternas extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Ventas externas';

    protected static ?string $title = 'Ventas externas';

    protected static string|\UnitEnum|null $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 24;

    protected static ?string $slug = 'ventas/externas';

    protected string $view = 'filament.pages.ventas.externas';

    public string $desde = '';

    public string $hasta = '';

    public ?int $canalId = null;

    public string $estado = 'activa';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('ventas-externas.view');
    }

    public function mount(): void
    {
        $this->desde = now()->startOfMonth()->toDateString();
        $this->hasta = now()->toDateString();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('filtros')
                ->label('Filtros')
                ->icon('heroicon-o-adjustments-horizontal')
                ->color('gray')
                ->modalHeading('Filtros de ventas externas')
                ->modalWidth('5xl')
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->modalSubmitActionLabel('Aplicar filtros')
                ->modalCancelActionLabel('Cancelar')
                ->fillForm(fn (): array => ['desde' => $this->desde, 'hasta' => $this->hasta, 'canal_id' => $this->canalId, 'estado' => $this->estado])
                ->schema([
                    Grid::make(['default' => 1, 'md' => 4])->schema([
                        DatePicker::make('desde')->label('Desde')->native(false)->required(),
                        DatePicker::make('hasta')->label('Hasta')->native(false)->required(),
                        Select::make('canal_id')->label('Canal')->options(fn (): array => $this->canalOptions())->native(false)->searchable()->placeholder('Todos'),
                        Select::make('estado')->label('Estado')->options(['activa' => 'Activa', 'anulada' => 'Anulada', 'todas' => 'Todas'])->native(),
                    ]),
                ])
                ->action(function (array $data): void {
                    $desde = (string) $data['desde'];
                    $hasta = (string) $data['hasta'];
                    if ($hasta < $desde) {
                        [$desde, $hasta] = [$hasta, $desde];
                    }

                    $this->desde = $desde;
                    $this->hasta = $hasta;
                    $this->canalId = filled($data['canal_id'] ?? null) ? (int) $data['canal_id'] : null;
                    $this->estado = (string) ($data['estado'] ?? 'activa');
                    $this->resetTable();
                }),
            Action::make('registrar')
                ->label('Registrar venta')
                ->icon('heroicon-o-plus')
                ->visible(fn (): bool => (bool) auth()->user()?->hasPermission('ventas-externas.registrar'))
                ->modalHeading('Registrar venta externa')
                ->modalWidth('5xl')
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->modalSubmitActionLabel('Guardar venta')
                ->modalCancelActionLabel('Cancelar')
                ->fillForm(fn (): array => ['fecha' => now()->toDateString(), 'canal_id' => $this->canalId, 'tickets' => 1, 'venta_sin_igv' => 0, 'igv' => 0, 'costo_sin_igv' => 0, 'venta_con_igv' => 0])
                ->schema($this->ventaSchema())
                ->action(function (array $data): void {
                    app(VentasExternasService::class)->registrar($data, auth()->id());
                    $this->resetTable();
                    Notification::make()->success()->title('Venta externa registrada')->send();
                }),
            Action::make('cuota')
                ->label('Registrar cuota')
                ->icon('heroicon-o-calendar-days')
                ->color('gray')
                ->visible(fn (): bool => (bool) auth()->user()?->hasPermission('ventas-externas.cuotas'))
                ->modalHeading('Registrar o actualizar cuota mensual')
                ->modalWidth('5xl')
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->modalSubmitActionLabel('Guardar cuota')
                ->modalCancelActionLabel('Cancelar')
                ->fillForm(fn (): array => ['periodo' => Carbon::parse($this->desde)->startOfMonth()->toDateString(), 'canal_id' => $this->canalId])
                ->schema([
                    Grid::make(['default' => 1, 'md' => 4])->schema([
                        DatePicker::make('periodo')->label('Mes')->native(false)->required(),
                        Select::make('canal_id')->label('Canal')->options(fn (): array => $this->canalOptions())->native(false)->searchable()->required(),
                        TextInput::make('cuota_sin_igv')->label('Cuota sin IGV')->numeric()->prefix('S/')->minValue(0)->required(),
                        TextInput::make('cuota_con_igv')->label('Cuota con IGV')->numeric()->prefix('S/')->minValue(0)->required(),
                    ]),
                ])
                ->action(fn (array $data) => $this->guardarCuota($data)),
        ];
    }

    /** @return array<int, Component> */
    private function ventaSchema(): array
    {
        return [
            Grid::make(['default' => 1, 'md' => 4])->schema([
                DatePicker::make('fecha')->label('Fecha')->native(false)->required()->maxDate(now()),
                Select::make('canal_id')->label('Canal')->options(fn (): array => $this->canalOptions())->native(false)->searchable()->required(),
                TextInput::make('tickets')->label('Tickets')->numeric()->minValue(1)->required(),
                TextInput::make('venta_sin_igv')->label('Venta sin IGV')->numeric()->prefix('S/')->minValue(0)->required()->live()
                    ->afterStateUpdated(fn (Set $set, Get $get) => $set('venta_con_igv', $this->totalFormulario($get))),
                TextInput::make('igv')->label('IGV')->numeric()->prefix('S/')->minValue(0)->required()->live()
                    ->afterStateUpdated(fn (Set $set, Get $get) => $set('venta_con_igv', $this->totalFormulario($get))),
                TextInput::make('venta_con_igv')->label('Venta con IGV')->numeric()->prefix('S/')->disabled()->dehydrated(false),
                TextInput::make('costo_sin_igv')->label('Costo sin IGV')->numeric()->prefix('S/')->minValue(0)->required(),
                Textarea::make('observacion')->label('Observación')->rows(2)->maxLength(1000)->columnSpanFull(),
            ]),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->tableQuery())
            ->columns([
                TextColumn::make('fecha')->label('Fecha')->date('d/m/Y')->sortable(),
                TextColumn::make('canal.codigo')->label('Código')->sortable(),
                TextColumn::make('canal.nombre')->label('Canal')->searchable()->wrap(),
                TextColumn::make('venta_sin_igv')->label('Sin IGV')->money('PEN')->alignEnd(),
                TextColumn::make('igv')->label('IGV')->money('PEN')->alignEnd()->toggleable(),
                TextColumn::make('venta_con_igv')->label('Con IGV')->money('PEN')->alignEnd(),
                TextColumn::make('tickets')->label('Tickets')->alignEnd(),
                TextColumn::make('tkp')->label('TKP')->state(fn (VentaExternaDiaria $record): ?float => $record->tickets > 0 ? round((float) $record->venta_con_igv / $record->tickets, 2) : null)->money('PEN')->alignEnd()->toggleable(),
                TextColumn::make('margen')->label('MB')->state(fn (VentaExternaDiaria $record): ?float => (float) $record->venta_sin_igv > 0 ? round((((float) $record->venta_sin_igv - (float) $record->costo_sin_igv) / (float) $record->venta_sin_igv) * 100, 2) : null)->suffix('%')->alignEnd()->toggleable(),
                TextColumn::make('estado')->label('Estado')->badge()->color(fn (string $state): string => $state === 'activa' ? 'success' : 'danger'),
            ])
            ->recordActions([
                Action::make('editar')
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (VentaExternaDiaria $record): bool => $record->estado === 'activa' && (bool) auth()->user()?->hasPermission('ventas-externas.editar'))
                    ->modalHeading('Editar venta externa')
                    ->modalWidth('5xl')
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->modalSubmitActionLabel('Guardar cambios')
                    ->modalCancelActionLabel('Cancelar')
                    ->fillForm(fn (VentaExternaDiaria $record): array => ['fecha' => $record->fecha->toDateString(), 'canal_id' => $record->canal_id, 'tickets' => $record->tickets, 'venta_sin_igv' => $record->venta_sin_igv, 'igv' => $record->igv, 'venta_con_igv' => $record->venta_con_igv, 'costo_sin_igv' => $record->costo_sin_igv, 'observacion' => $record->observacion])
                    ->schema($this->ventaSchema())
                    ->action(function (VentaExternaDiaria $record, array $data): void {
                        app(VentasExternasService::class)->actualizar($record, $data, auth()->id());
                        $this->resetTable();
                        Notification::make()->success()->title('Venta externa actualizada')->send();
                    }),
                Action::make('anular')
                    ->label('Anular')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (VentaExternaDiaria $record): bool => $record->estado === 'activa' && (bool) auth()->user()?->hasPermission('ventas-externas.anular'))
                    ->requiresConfirmation()
                    ->modalHeading('Anular venta externa')
                    ->modalDescription('La venta queda conservada en el historial y deja de sumar los indicadores.')
                    ->modalSubmitActionLabel('Anular venta')
                    ->schema([Textarea::make('motivo')->label('Motivo')->required()->rows(2)->maxLength(1000)])
                    ->action(function (VentaExternaDiaria $record, array $data): void {
                        app(VentasExternasService::class)->anular($record, auth()->id(), $data['motivo'] ?? null);
                        $this->resetTable();
                        Notification::make()->success()->title('Venta externa anulada')->send();
                    }),
                Action::make('historial')
                    ->label('Historial')
                    ->icon('heroicon-o-clock')
                    ->modalHeading('Historial de la venta externa')
                    ->modalWidth('5xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar')
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->modalContent(fn (VentaExternaDiaria $record) => view('filament.pages.ventas.partials.venta-externa-historial', ['venta' => $record->load(['auditorias.usuario'])])),
            ])
            ->defaultSort('fecha', 'desc')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('No hay ventas externas para los filtros seleccionados.');
    }

    /** @return array<string, mixed> */
    public function resumen(): array
    {
        $sums = $this->activaQuery()->selectRaw('COALESCE(SUM(venta_sin_igv), 0) as sin_igv, COALESCE(SUM(venta_con_igv), 0) as con_igv, COALESCE(SUM(costo_sin_igv), 0) as costo, COALESCE(SUM(tickets), 0) as tickets')->first();
        $cuota = CuotaVentaExterna::query()
            ->whereBetween('periodo', [Carbon::parse($this->desde)->startOfMonth()->toDateString(), Carbon::parse($this->hasta)->startOfMonth()->toDateString()])
            ->when($this->canalId, fn (Builder $query) => $query->where('canal_id', $this->canalId))
            ->sum('cuota_sin_igv');
        $sinIgv = (float) $sums->sin_igv;
        $tickets = (int) $sums->tickets;

        return [
            'sin_igv' => $sinIgv,
            'con_igv' => (float) $sums->con_igv,
            'cuota_sin_igv' => (float) $cuota,
            'avance' => (float) $cuota > 0 ? ($sinIgv / (float) $cuota) * 100 : null,
            'tkp' => $tickets > 0 ? (float) $sums->con_igv / $tickets : null,
            'mb' => $sinIgv > 0 ? (($sinIgv - (float) $sums->costo) / $sinIgv) * 100 : null,
        ];
    }

    /** @return array<int, string> */
    private function canalOptions(): array
    {
        return CanalVentaExterna::query()->where('activo', true)->orderBy('nombre')->get()
            ->mapWithKeys(fn (CanalVentaExterna $canal): array => [$canal->id => $canal->codigo.' · '.$canal->nombre])->all();
    }

    private function tableQuery(): Builder
    {
        return VentaExternaDiaria::query()->with('canal')
            ->whereBetween('fecha', [$this->desde, $this->hasta])
            ->when($this->canalId, fn (Builder $query) => $query->where('canal_id', $this->canalId))
            ->when($this->estado !== 'todas', fn (Builder $query) => $query->where('estado', $this->estado));
    }

    private function activaQuery(): Builder
    {
        return VentaExternaDiaria::query()->where('estado', 'activa')
            ->whereBetween('fecha', [$this->desde, $this->hasta])
            ->when($this->canalId, fn (Builder $query) => $query->where('canal_id', $this->canalId));
    }

    private function totalFormulario(Get $get): float
    {
        return round((float) ($get('venta_sin_igv') ?? 0) + (float) ($get('igv') ?? 0), 2);
    }

    /** @param array<string, mixed> $data */
    private function guardarCuota(array $data): void
    {
        $periodo = Carbon::parse((string) $data['periodo'])->startOfMonth()->toDateString();
        DB::transaction(function () use ($data, $periodo): void {
            $cuota = CuotaVentaExterna::query()->firstOrNew(['canal_id' => (int) $data['canal_id'], 'periodo' => $periodo]);
            $antes = $cuota->exists ? $cuota->toArray() : null;
            $cuota->fill([
                'cuota_sin_igv' => round((float) $data['cuota_sin_igv'], 2),
                'cuota_con_igv' => round((float) $data['cuota_con_igv'], 2),
                'actualizado_por' => auth()->id(),
            ]);
            $cuota->save();
            DB::table('cuota_venta_externa_auditorias')->insert([
                'cuota_venta_externa_id' => $cuota->id,
                'antes' => $antes ? json_encode($antes) : null,
                'despues' => json_encode($cuota->fresh()->toArray()),
                'usuario_id' => auth()->id(),
                'created_at' => now(),
            ]);
        });

        Notification::make()->success()->title('Cuota mensual actualizada')->send();
    }
}
