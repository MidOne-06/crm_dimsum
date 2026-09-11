<?php

namespace App\Livewire\Ventas;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Tables\TableComponent;
use Illuminate\Pagination\LengthAwarePaginator;

class RankingProductosTable extends TableComponent
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    public function table(Table $table): Table
    {
        return $table
            ->heading('Ranking productos')
            ->queryStringIdentifier('rankingProductos')
            ->records(fn (int $page, int $recordsPerPage): LengthAwarePaginator => $this->records($page, $recordsPerPage))
            ->columns([
                TextColumn::make('codigo')->label('Ítem')->weight('medium'),
                TextColumn::make('descripcion')->label('Producto')->weight('medium')->wrap(),
                TextColumn::make('importe')->label('Venta')->money('PEN')->alignEnd(),
                TextColumn::make('participacion')->label('Participación')->suffix('%')->numeric(2)->alignEnd(),
            ])
            ->stackedOnMobile()
            ->paginated([8, 25, 50])
            ->defaultPaginationPageOption(8)
            ->emptyStateHeading('No hay productos para el filtro.');
    }

    protected function records(int $page, int $recordsPerPage): LengthAwarePaginator
    {
        $rows = collect($this->rows)
            ->values()
            ->map(fn (array $row, int $index): array => [
                'key' => 'producto-'.($index + 1),
                'codigo' => (string) ($row['codigo'] ?? '—'),
                'descripcion' => (string) ($row['descripcion'] ?? '—'),
                'importe' => (float) ($row['importe'] ?? 0),
                'participacion' => isset($row['participacion']) ? (float) $row['participacion'] : null,
            ]);

        return new LengthAwarePaginator(
            $rows->forPage($page, $recordsPerPage)->values(),
            $rows->count(),
            $recordsPerPage,
            $page,
            ['path' => request()->url(), 'pageName' => 'rankingProductosPage'],
        );
    }
}
