<?php

namespace App\Filament\Pages\Ventas;

use App\Services\IndicadoresComercialesService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\WithPagination;

class IndicadoresComerciales extends Page
{
    use WithPagination;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationLabel = 'Indicadores';

    protected static ?string $title = '';

    protected static string|\UnitEnum|null $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 19;

    protected static ?string $slug = 'ventas/indicadores';

    protected string $view = 'filament.pages.ventas.indicadores-comerciales';

    public string $desde = '';

    public string $hasta = '';

    /** @var array<int, string> */
    public array $unidades = [];

    /** @var array<string, mixed>|null */
    protected ?array $tableroCache = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('ventas.indicadores.view');
    }

    public function mount(): void
    {
        $this->desde = now()->startOfMonth()->toDateString();
        $this->hasta = now()->toDateString();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('costosRecetas')
                ->label('Costos y recetas')
                ->icon('heroicon-o-calculator')
                ->color('gray')
                ->visible(fn (): bool => (bool) auth()->user()?->hasPermission('ventas.costos.view'))
                ->url(CostosRecetasComerciales::getUrl()),
            Action::make('filtros')
                ->label('Filtros')
                ->icon('heroicon-o-adjustments-horizontal')
                ->color('gray')
                ->modalHeading('Filtros de indicadores')
                ->modalWidth('5xl')
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->modalSubmitActionLabel('Aplicar filtros')
                ->modalCancelActionLabel('Cancelar')
                ->fillForm(fn (): array => [
                    'desde' => $this->desde,
                    'hasta' => $this->hasta,
                    'alcance' => $this->unidades === [] ? 'todas' : 'seleccion',
                    'unidades' => $this->unidades,
                ])
                ->schema([
                    Grid::make(['default' => 1, 'md' => 4])->schema([
                        Select::make('alcance')
                            ->label('Tiendas / canales')
                            ->options(['todas' => 'Todas', 'seleccion' => 'Seleccionar'])
                            ->native()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, mixed $state): void {
                                if ($state === 'todas') {
                                    $set('unidades', []);
                                }
                            })
                            ->columnSpan(['default' => 1, 'md' => 2]),
                        DatePicker::make('desde')->label('Desde')->native(false)->required(),
                        DatePicker::make('hasta')->label('Hasta')->native(false)->required(),
                        Select::make('unidades')
                            ->label('Seleccionar tiendas / canales')
                            ->options(fn (): array => $this->opcionesUnidades())
                            ->multiple()
                            ->searchable()
                            ->native(false)
                            ->optionsLimit(12)
                            ->hidden(fn (Get $get): bool => $get('alcance') !== 'seleccion')
                            ->columnSpanFull(),
                    ]),
                ])
                ->action(function (array $data): void {
                    $desde = Carbon::parse((string) $data['desde'])->startOfDay();
                    $hasta = Carbon::parse((string) $data['hasta'])->startOfDay();
                    if ($hasta->lessThan($desde)) {
                        [$desde, $hasta] = [$hasta, $desde];
                    }

                    $this->desde = $desde->toDateString();
                    $this->hasta = $hasta->toDateString();
                    $this->unidades = ($data['alcance'] ?? 'todas') === 'seleccion'
                        ? array_values(array_filter((array) ($data['unidades'] ?? []), 'is_string'))
                        : [];
                    $this->tableroCache = null;
                    $this->resetPage('rankingVentasPage');
                    $this->resetPage('rankingProductosPage');
                }),
        ];
    }

    /** @return array<string, mixed> */
    public function tablero(): array
    {
        return $this->tableroCache ??= app(IndicadoresComercialesService::class)->tablero(
            Carbon::parse($this->desde)->startOfDay(),
            Carbon::parse($this->hasta)->startOfDay(),
            $this->unidades,
            auth()->user(),
        );
    }

    /** @return array<string, string> */
    public function opcionesUnidades(): array
    {
        return app(IndicadoresComercialesService::class)->opcionesUnidades(auth()->user());
    }

    /** @return array<string, mixed> */
    public function filtrosGrafico(): array
    {
        return ['desde' => $this->desde, 'hasta' => $this->hasta, 'unidades' => $this->unidades];
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function rankingVentasPaginado(): LengthAwarePaginator
    {
        return $this->paginarRanking($this->tablero()['ranking_locales'], 'rankingVentasPage');
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function rankingProductosPaginado(): LengthAwarePaginator
    {
        return $this->paginarRanking($this->tablero()['ranking_productos'], 'rankingProductosPage');
    }

    /** @param Collection<int, array<string, mixed>> $filas @return LengthAwarePaginator<int, array<string, mixed>> */
    private function paginarRanking(Collection $filas, string $pageName): LengthAwarePaginator
    {
        $porPagina = 8;
        $pagina = $this->getPage($pageName);

        return new LengthAwarePaginator(
            $filas->forPage($pagina, $porPagina)->values(),
            $filas->count(),
            $porPagina,
            $pagina,
            ['path' => request()->url(), 'pageName' => $pageName],
        );
    }
}
