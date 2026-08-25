<?php

namespace App\Filament\Pages\Kardex;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Models\KardexMovimiento;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\WithPagination;

class KardexHistorico extends Page
{
    use WithPagination;
    use ScopesLocalsToUser;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationLabel = 'Histórico de kardex';

    protected static ?string $title = 'Histórico de kardex';

    protected static string|\UnitEnum|null $navigationGroup = 'Kardex';

    protected static ?int $navigationSort = 32;

    protected static ?string $slug = 'kardex/historico';

    protected string $view = 'filament.pages.kardex.historico';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('kardex.historico.view');
    }

    public array $localOptions = [];

    public array $motivoOptions = [];

    public ?array $data = [];

    public string $activeDatePreset = 'today';

    public bool $hasSearched = false;

    public function mount(): void
    {
        $today = now()->toDateString();

        $this->localOptions = $this->scopeKeyedLocalsToUser(KardexMovimiento::query()
            ->whereNotNull('local_id')
            ->select('local_id', 'local_nombre')
            ->distinct()
            ->orderBy('local_nombre')
            ->get()
            ->pluck('local_nombre', 'local_id')
            ->all());

        $this->motivoOptions = KardexMovimiento::query()
            ->whereNotNull('motivo')
            ->distinct()
            ->orderBy('motivo')
            ->pluck('motivo', 'motivo')
            ->all();

        $this->form->fill([
            'selectedLocals' => array_keys($this->localOptions),
            'motivo' => '',
            'producto' => '',
            'order' => '1',
        ]);

        $this->data['dateStart'] = now()->startOfMonth()->toDateString();
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
                Grid::make(['default' => 1, 'md' => 3])
                    ->schema([
                        Select::make('motivo')->label('Motivo')->native(false)
                            ->options($this->motivoOptions)
                            ->placeholder('Todos'),
                        TextInput::make('producto')->label('Producto / ítem')->placeholder('Buscar por nombre…'),
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

    public function rows(): LengthAwarePaginator
    {
        return $this->query()->paginate(25);
    }

    /** @return array{entradas: float, salidas: float, movimientos: int} */
    public function resumen(): array
    {
        $base = $this->query();

        return [
            'entradas' => (clone $base)->sum('entrada'),
            'salidas' => (clone $base)->sum('salida'),
            'movimientos' => (clone $base)->count(),
        ];
    }

    protected function query(): Builder
    {
        $selectedLocals = array_keys($this->scopeKeyedLocalsToUser(
            array_fill_keys($this->data['selectedLocals'] ?? [], true),
        ));
        $motivo = $this->data['motivo'] ?? '';
        $producto = trim((string) ($this->data['producto'] ?? ''));
        $order = ($this->data['order'] ?? '1') === '2' ? 'asc' : 'desc';

        return KardexMovimiento::query()
            ->when(! empty($selectedLocals), fn (Builder $query) => $query->whereIn('local_id', $selectedLocals))
            ->when($motivo !== '', fn (Builder $query) => $query->where('motivo', $motivo))
            ->when($producto !== '', fn (Builder $query) => $query->where('item_nombre', 'ilike', "%{$producto}%"))
            ->whereBetween('fecha', [
                $this->data['dateStart'] ?? now()->toDateString(),
                $this->data['dateEnd'] ?? now()->toDateString(),
            ])
            ->orderBy('fecha_hora', $order);
    }
}
