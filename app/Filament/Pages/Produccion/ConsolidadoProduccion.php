<?php

namespace App\Filament\Pages\Produccion;

use App\Models\ProduccionDiariaTanda;
use App\Models\ProduccionProducto;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ConsolidadoProduccion extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-table-cells';
    protected static ?string $navigationLabel = 'Consolidado de producción';
    protected static ?string $title = 'Consolidado de producción';
    protected static string|\UnitEnum|null $navigationGroup = 'Producción';
    protected static ?int $navigationSort = 3;
    protected static ?string $slug = 'produccion/consolidado';
    protected string $view = 'filament.pages.produccion.consolidado-produccion';

    /** @var array<string, mixed> */
    public array $data = [];
    private ?string $cacheMes = null;
    /** @var array<int, array<int, float>> */
    private array $totales = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();
        return (bool) ($user?->hasPermission('produccion-diaria.view') || $user?->hasPermission('produccion-diaria.registrar') || $user?->hasPermission('produccion-diaria.aprobar'));
    }

    public function mount(): void
    {
        $this->form->fill(['mes' => $this->mesActual()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('mes')->label('Mes')->options(fn (): array => $this->opcionesMes())->native(false)->required(),
        ])->statePath('data');
    }

    public function aplicarMes(): void
    {
        $state = $this->form->getState();
        $this->data['mes'] = $state['mes'];
        $this->cacheMes = null;
        $this->totales = [];
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->productosQuery())
            ->columns([
                Tables\Columns\TextColumn::make('codigo')->label('Código')->searchable(),
                Tables\Columns\TextColumn::make('nombre')->label('Producto')->searchable()->sortable()->weight('medium')->wrap(),
                Tables\Columns\TextColumn::make('unidad')->label('Unidad'),
                ...$this->columnasDias(),
                Tables\Columns\TextColumn::make('total_mes')->label('Total')->state(fn (ProduccionProducto $producto): string => number_format(array_sum($this->cantidades($producto->id)), 2))
                    ->alignEnd()->weight('bold'),
            ])
            ->defaultSort('nombre')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->recordAction(null)
            ->recordUrl(null)
            ->emptyStateHeading('Sin registros.');
    }

    /** @return array<int, Tables\Columns\TextColumn> */
    private function columnasDias(): array
    {
        return collect(range(1, $this->periodo()->daysInMonth))->map(fn (int $dia): Tables\Columns\TextColumn => Tables\Columns\TextColumn::make('dia_'.$dia)
            ->label((string) $dia)
            ->state(function (ProduccionProducto $producto) use ($dia): string {
                $cantidad = $this->cantidades($producto->id)[$dia] ?? 0;
                return $cantidad > 0 ? number_format($cantidad, 2) : '—';
            })->alignEnd())->all();
    }

    private function productosQuery(): Builder
    {
        [$inicio, $fin] = $this->rangoMes();
        return ProduccionProducto::query()->whereHas('tandas', fn (Builder $query) => $query->whereHas('cierre', fn (Builder $cierre) => $cierre->whereBetween('fecha', [$inicio->toDateString(), $fin->toDateString()])));
    }

    /** @return array<int, float> */
    private function cantidades(int $productoId): array
    {
        $mes = $this->mesSeleccionado();
        if ($this->cacheMes !== $mes) {
            $this->cacheMes = $mes;
            $this->totales = [];
            [$inicio, $fin] = $this->rangoMes();
            ProduccionDiariaTanda::query()
                ->selectRaw('producto_id, EXTRACT(DAY FROM produccion_diaria_cierres.fecha)::integer as dia, SUM(cantidad) as cantidad')
                ->join('produccion_diaria_cierres', 'produccion_diaria_cierres.id', '=', 'produccion_diaria_tandas.cierre_id')
                ->whereNotNull('producto_id')
                ->whereBetween('produccion_diaria_cierres.fecha', [$inicio->toDateString(), $fin->toDateString()])
                ->groupBy('producto_id', 'dia')->get()
                ->each(function (ProduccionDiariaTanda $fila): void {
                    $this->totales[(int) $fila->producto_id][(int) $fila->dia] = (float) $fila->cantidad;
                });
        }

        return $this->totales[$productoId] ?? [];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function rangoMes(): array
    {
        $inicio = $this->periodo();
        return [$inicio, $inicio->copy()->endOfMonth()];
    }

    private function periodo(): Carbon { return Carbon::parse($this->mesSeleccionado().'-01', 'America/Lima')->startOfMonth(); }
    private function mesSeleccionado(): string { return (string) ($this->data['mes'] ?? $this->mesActual()); }
    private function mesActual(): string { return Carbon::now('America/Lima')->format('Y-m'); }

    /** @return array<string, string> */
    private function opcionesMes(): array
    {
        return collect(range(0, 23))->mapWithKeys(function (int $offset): array {
            $fecha = Carbon::now('America/Lima')->startOfMonth()->subMonths($offset);
            return [$fecha->format('Y-m') => ucfirst($fecha->locale('es')->isoFormat('MMMM YYYY'))];
        })->all();
    }
}
