<?php

namespace App\Livewire\RequerimientosStock;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Reactive;
use Livewire\Component;

class Tabla extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    #[Reactive]
    public array $rows = [];

    #[Reactive]
    public array $columns = [];

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (int $page, int $recordsPerPage): LengthAwarePaginator => $this->records($page, $recordsPerPage))
            ->columns(collect($this->columns)->map(function (array $column, string $name): TextColumn {
                $text = TextColumn::make($name)
                    ->label($column['label'] ?? $name)
                    ->wrap();

                if (($column['numeric'] ?? false) === true) {
                    $text->formatStateUsing(fn ($state): string => number_format(
                        (float) $state,
                        (int) ($column['decimals'] ?? 0),
                        '.',
                        ',',
                    ))->alignEnd();
                }

                return $text;
            })->all())
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('Sin registros.');
    }

    protected function records(int $page, int $recordsPerPage): LengthAwarePaginator
    {
        $rows = collect($this->rows)->map(function (array $row): array {
            return collect($this->columns)->mapWithKeys(fn (array $column, string $name): array => [
                $name => filled($row[$name] ?? null) ? $row[$name] : '—',
            ])->all();
        })->values();

        return new LengthAwarePaginator(
            $rows->forPage($page, $recordsPerPage)->values(),
            $rows->count(),
            $recordsPerPage,
            $page,
            ['path' => request()->url()],
        );
    }

    public function render()
    {
        return view('livewire.requerimientos-stock.tabla');
    }
}
