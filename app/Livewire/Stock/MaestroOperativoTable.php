<?php

namespace App\Livewire\Stock;

use Filament\Forms\Components\Select;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Reactive;
use Livewire\Component;

class MaestroOperativoTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    /** @var array<int, array<string, mixed>> */
    #[Reactive]
    public array $rows = [];

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (int $page, int $recordsPerPage): LengthAwarePaginator => $this->records($page, $recordsPerPage))
            ->columns([
                TextColumn::make('local')->label('Local')->searchable()->sortable()->wrap(),
                TextColumn::make('almacen')->label('Almacén')->searchable()->sortable()->wrap(),
                TextColumn::make('item')->label('Ítem')->searchable()->sortable()->wrap(),
                TextColumn::make('tipo')->label('Tipo')->toggleable()->wrap(),
                TextColumn::make('fecha')->label('Último cuadre')->toggleable(),
                TextColumn::make('stockActual')->label('Stock actual')->numeric(3)->alignEnd()
                    ->suffix(fn (array $record): string => filled($record['unidad'] ?? null) ? ' '.$record['unidad'] : ''),
            ])
            ->filters([
                Filter::make('resultados')
                    ->label('Filtros')
                    ->schema([
                        Select::make('local')->label('Local')->native(false)->searchable()->options(fn (): array => $this->filterOptions('local'))->placeholder('Todos los locales'),
                        Select::make('almacen')->label('Almacén')->native(false)->searchable()->options(fn (): array => $this->filterOptions('almacen'))->placeholder('Todos los almacenes'),
                        Select::make('item')->label('Ítem')->native(false)->searchable()->options(fn (): array => $this->filterOptions('item'))->placeholder('Todos los ítems'),
                        Select::make('tipo')->label('Tipo')->native(false)->searchable()->options(fn (): array => $this->filterOptions('tipo'))->placeholder('Todos los tipos'),
                    ]),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(['default' => 1, 'md' => 2, 'xl' => 4])
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('Sin stock para los filtros seleccionados.');
    }

    /** @return array<string, string> */
    public function filterOptions(string $field): array
    {
        return collect($this->rows)
            ->pluck($field)
            ->filter()
            ->unique()
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->mapWithKeys(fn (string $value): array => [$value => $value])
            ->all();
    }

    protected function records(int $page, int $recordsPerPage): LengthAwarePaginator
    {
        $rows = $this->filteredRows();

        return new LengthAwarePaginator(
            $rows->forPage($page, $recordsPerPage)->values(),
            $rows->count(),
            $recordsPerPage,
            $page,
            ['path' => request()->url(), 'pageName' => 'stockMaestroPage'],
        );
    }

    /** @return Collection<int, array<string, mixed>> */
    protected function filteredRows(): Collection
    {
        $filters = $this->tableFilters['resultados'] ?? [];

        return collect($this->rows)
            ->filter(function (array $row) use ($filters): bool {
                foreach (['local', 'almacen', 'item', 'tipo'] as $field) {
                    if (filled($filters[$field] ?? null) && ($row[$field] ?? null) !== $filters[$field]) {
                        return false;
                    }
                }

                return true;
            })
            ->values();
    }

    public function updatedRows(): void
    {
        $this->resetTable();
    }

    public function render()
    {
        return view('livewire.stock.maestro-operativo-table');
    }
}
