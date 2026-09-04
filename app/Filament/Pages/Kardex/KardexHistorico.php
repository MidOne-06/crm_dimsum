<?php

namespace App\Filament\Pages\Kardex;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Models\KardexMovimiento;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class KardexHistorico extends Page implements HasTable
{
    use InteractsWithTable;
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

    public array $almacenOptions = [];

    public ?array $data = [];

    public string $activeDatePreset = 'month';

    public function mount(): void
    {
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

        $this->almacenOptions = KardexMovimiento::query()
            ->whereNotNull('almacen')
            ->where('almacen', '!=', '')
            ->distinct()
            ->orderBy('almacen')
            ->pluck('almacen', 'almacen')
            ->all();

        $this->form->fill([
            // Vacío significa "todos los locales permitidos". No se cargan
            // como chips individuales para mantener el filtro compacto.
            'selectedLocals' => [],
            'almacen' => [],
            'motivo' => [],
            'tipoMovimiento' => '',
            'producto' => '',
        ]);

        $this->data['dateStart'] = now()->startOfMonth()->toDateString();
        $this->data['dateEnd'] = now()->toDateString();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['default' => 1, 'md' => 2, 'xl' => 3])
                    ->schema([
                        Select::make('selectedLocals')
                            ->label('Locales')
                            ->options($this->localOptions)
                            ->multiple()
                            ->native(false)
                            ->searchable()
                            ->preload()
                            ->placeholder('Todos los locales permitidos'),
                        Select::make('almacen')
                            ->label('Almacén')
                            ->options($this->almacenOptions)
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->placeholder('Todos los almacenes'),
                        Select::make('motivo')
                            ->label('Motivo')
                            ->options($this->motivoOptions)
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->placeholder('Todos los motivos'),
                        Select::make('tipoMovimiento')
                            ->label('Tipo de movimiento')
                            ->options([
                                'entrada' => 'Solo entradas',
                                'salida' => 'Solo salidas',
                                'ambos' => 'Con entrada y salida',
                            ])
                            ->placeholder('Todos los movimientos'),
                        TextInput::make('producto')
                            ->label('Producto o código')
                            ->placeholder('Nombre o código interno...')
                            ->maxLength(100),
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

    /** @return array{0: string, 1: string} */
    public function dateRangeForDisplay(): array
    {
        return [
            (string) ($this->data['dateStart'] ?? now()->startOfMonth()->toDateString()),
            (string) ($this->data['dateEnd'] ?? now()->toDateString()),
        ];
    }

    public function usesHistoricalCoverage(): bool
    {
        return false;
    }

    public function search(): void
    {
        $this->resetPage();
    }

    public function abrirFiltros(): void
    {
        $this->dispatch('open-modal', id: 'filtros-kardex-historico');
    }

    public function cerrarFiltros(): void
    {
        $this->dispatch('close-modal', id: 'filtros-kardex-historico');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->baseQuery())
            ->columns([
                TextColumn::make('fecha_hora')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('local_nombre')
                    ->label('Local')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('almacen')
                    ->label('Almacén')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('item_nombre')
                    ->label('Producto')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('motivo')
                    ->label('Motivo')
                    ->searchable()
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('entrada')
                    ->label('Entrada')
                    ->state(fn (KardexMovimiento $record): ?string => $record->entrada > 0 ? number_format((float) $record->entrada, 3) : null)
                    ->color('success')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('salida')
                    ->label('Salida')
                    ->state(fn (KardexMovimiento $record): ?string => $record->salida > 0 ? number_format((float) $record->salida, 3) : null)
                    ->color('danger')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('stock')
                    ->label('Stock')
                    ->numeric(3)
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('stock_valorizado')
                    ->label('Stock valorizado')
                    ->numeric(2)
                    ->alignEnd()
                    ->sortable(),
            ])
            ->defaultSort('fecha_hora', 'desc')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('No hay movimientos para los filtros seleccionados.');
    }

    /** @return array{entradas: float, salidas: float, movimientos: int} */
    public function resumen(): array
    {
        $base = $this->getFilteredTableQuery() ?? $this->baseQuery();

        return [
            'entradas' => (clone $base)->sum('entrada'),
            'salidas' => (clone $base)->sum('salida'),
            'movimientos' => (clone $base)->count(),
        ];
    }

    protected function baseQuery(): Builder
    {
        $selectedLocals = $this->restrictLocalIdsToUser($this->data['selectedLocals'] ?? []);
        $almacenes = $this->data['almacen'] ?? [];
        $motivos = $this->data['motivo'] ?? [];
        $tipoMovimiento = $this->data['tipoMovimiento'] ?? '';
        $producto = trim((string) ($this->data['producto'] ?? ''));
        $desde = $this->data['dateStart'] ?? now()->startOfMonth()->toDateString();
        $hasta = $this->data['dateEnd'] ?? now()->toDateString();

        $query = KardexMovimiento::query()
            ->when(filled($selectedLocals), fn (Builder $query): Builder => $query->whereIn('local_id', $selectedLocals))
            ->when(filled($almacenes), fn (Builder $query): Builder => $query->whereIn('almacen', $almacenes))
            ->when(filled($motivos), fn (Builder $query): Builder => $query->whereIn('motivo', $motivos))
            ->whereDate('fecha', '>=', $desde)
            ->whereDate('fecha', '<=', $hasta);

        if (auth()->user()?->isRestrictedToLocals() && blank($selectedLocals)) {
            $query->whereIn('local_id', array_keys($this->localOptions));
        }

        if ($tipoMovimiento === 'entrada') {
            $query->where('entrada', '>', 0);
        } elseif ($tipoMovimiento === 'salida') {
            $query->where('salida', '>', 0);
        } elseif ($tipoMovimiento === 'ambos') {
            $query->where('entrada', '>', 0)->where('salida', '>', 0);
        }

        if ($producto !== '') {
            $query->where(function (Builder $query) use ($producto): void {
                $query->where('item_nombre', 'ilike', "%{$producto}%")
                    ->orWhere('cod_interno', 'ilike', "%{$producto}%")
                    ->orWhere('producto', 'ilike', "%{$producto}%");
            });
        }

        return $query;
    }
}
