<?php

namespace App\Filament\Pages\Ventas;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Models\Venta;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\WithPagination;

class HistoricoVentas extends Page
{
    use WithPagination;
    use ScopesLocalsToUser;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationLabel = 'Histórico de ventas';

    protected static ?string $title = 'Histórico de ventas';

    protected static string|\UnitEnum|null $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 23;

    protected static ?string $slug = 'ventas/historico';

    protected string $view = 'filament.pages.ventas.historico';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('ventas.historico.view');
    }

    public array $localOptions = [];

    public array $comprobanteOptions = [];

    public array $estadoOptions = [];

    public array $monedaOptions = [];

    public ?array $data = [];

    public string $activeDatePreset = 'today';

    public bool $hasSearched = false;

    public ?string $detailId = null;

    public function mount(): void
    {
        $today = now()->toDateString();

        $this->localOptions = $this->scopeKeyedLocalsToUser(Venta::query()
            ->whereNotNull('local_id')
            ->select('local_id', 'local')
            ->distinct()
            ->orderBy('local')
            ->get()
            ->pluck('local', 'local_id')
            ->all());

        $this->comprobanteOptions = Venta::query()->whereNotNull('comprobante_tipo')->distinct()->orderBy('comprobante_tipo')->pluck('comprobante_tipo', 'comprobante_tipo')->all();
        $this->estadoOptions = Venta::query()->whereNotNull('estado')->distinct()->orderBy('estado')->pluck('estado', 'estado')->all();
        $this->monedaOptions = Venta::query()->whereNotNull('moneda')->distinct()->orderBy('moneda')->pluck('moneda', 'moneda')->all();

        $this->form->fill([
            'selectedLocals' => array_keys($this->localOptions),
            'comprobante' => '',
            'estado' => '',
            'moneda' => '',
            'order' => '1',
        ]);

        $this->data['dateStart'] = $today;
        $this->data['dateEnd'] = $today;
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
                            ->options($this->localOptions)
                            ->columns(['default' => 1, 'sm' => 2, 'lg' => 3, 'xl' => 4])
                            ->bulkToggleable()
                            ->searchable(),
                    ]),
                Grid::make(['default' => 1, 'md' => 2, 'xl' => 4])
                    ->schema([
                        Select::make('comprobante')->label('Comprobante')->native(false)
                            ->options($this->comprobanteOptions)
                            ->placeholder('Todos'),
                        Select::make('estado')->label('Estado')->native(false)
                            ->options($this->estadoOptions)
                            ->placeholder('Todos'),
                        Select::make('moneda')->label('Moneda')->native(false)
                            ->options($this->monedaOptions)
                            ->placeholder('Todas'),
                        Select::make('order')->label('Orden')->native(false)
                            ->options(['1' => 'Descendente', '2' => 'Ascendente']),
                    ]),
            ])
            ->statePath('data');
    }

    public function syncDateRange(string $start, string $end, string $preset = 'custom'): void
    {
        $this->data['dateStart'] = $start;
        $this->data['dateEnd'] = $end;
        $this->activeDatePreset = $preset;
    }

    public function search(): void
    {
        $this->hasSearched = true;
        $this->resetPage();
    }

    public function openDetail(string $id): void
    {
        if (! auth()->user()?->hasPermission('ventas.historico.ver-detalle')) {
            return;
        }

        $this->detailId = $id;
        $this->dispatch('open-modal', id: 'sale-history-detail-modal');
    }

    public function closeDetail(): void
    {
        $this->detailId = null;
        $this->dispatch('close-modal', id: 'sale-history-detail-modal');
    }

    public function detail(): ?Venta
    {
        return $this->detailId ? Venta::with('detalles')->find($this->detailId) : null;
    }

    public function rows(): LengthAwarePaginator
    {
        return $this->query()->paginate(20);
    }

    protected function query(): Builder
    {
        // Se lee directo de $this->data (no de $this->form->getState()): el Schema
        // cachea su propio árbol de componentes y puede quedar desincronizado del
        // estado real entre renders, mientras que $this->data siempre refleja el
        // valor vigente (mismo motivo por el que dateStart/dateEnd ya se leían así).
        // Aunque el checkbox de locales ya viene filtrado a los asignados, se
        // vuelve a intersectar aquí -- defensa en profundidad ante un usuario
        // que manipule el request para pedir un local fuera de su alcance.
        $selectedLocals = array_keys($this->scopeKeyedLocalsToUser(
            array_fill_keys($this->data['selectedLocals'] ?? [], true),
        ));
        $comprobante = $this->data['comprobante'] ?? '';
        $estado = $this->data['estado'] ?? '';
        $moneda = $this->data['moneda'] ?? '';
        $order = ($this->data['order'] ?? '1') === '2' ? 'asc' : 'desc';

        return Venta::query()
            ->when(! empty($selectedLocals), fn (Builder $query) => $query->whereIn('local_id', $selectedLocals))
            ->when(! empty($comprobante), fn (Builder $query) => $query->where('comprobante_tipo', $comprobante))
            ->when(! empty($estado), fn (Builder $query) => $query->where('estado', $estado))
            ->when(! empty($moneda), fn (Builder $query) => $query->where('moneda', $moneda))
            ->whereBetween('venta_fecha', [
                ($this->data['dateStart'] ?? now()->toDateString()).' 00:00:00',
                ($this->data['dateEnd'] ?? now()->toDateString()).' 23:59:59',
            ])
            ->orderBy('venta_fecha', $order);
    }
}
