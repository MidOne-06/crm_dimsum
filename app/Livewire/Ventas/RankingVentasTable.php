<?php

namespace App\Livewire\Ventas;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Tables\TableComponent;
use Illuminate\Pagination\LengthAwarePaginator;

class RankingVentasTable extends TableComponent
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    public function table(Table $table): Table
    {
        return $table
            ->heading('Ranking ventas')
            ->queryStringIdentifier('rankingVentas')
            ->records(fn (int $page, int $recordsPerPage): LengthAwarePaginator => $this->records($page, $recordsPerPage))
            ->columns([
                TextColumn::make('codigo')->label('Código')->weight('medium'),
                TextColumn::make('nombre')->label('Tienda')->weight('medium')->wrap(),
                TextColumn::make('sin_igv')->label('Venta')->money('PEN')->alignEnd(),
                TextColumn::make('cuota_sin_igv')->label('Cuota')->money('PEN')->alignEnd(),
                TextColumn::make('avance')->label('Avance')->suffix('%')->numeric(2)->alignEnd(),
            ])
            ->stackedOnMobile()
            ->paginated([8, 25, 50])
            ->defaultPaginationPageOption(8)
            ->emptyStateHeading('No hay unidades para el filtro.');
    }

    protected function records(int $page, int $recordsPerPage): LengthAwarePaginator
    {
        $rows = collect($this->rows)
            ->values()
            ->map(fn (array $row, int $index): array => [
                'key' => 'venta-'.($index + 1),
                'codigo' => (string) ($row['codigo'] ?? '—'),
                'nombre' => (string) ($row['nombre'] ?? '—'),
                'sin_igv' => (float) ($row['sin_igv'] ?? 0),
                'cuota_sin_igv' => isset($row['cuota_sin_igv']) ? (float) $row['cuota_sin_igv'] : null,
                'avance' => isset($row['avance']) ? (float) $row['avance'] : null,
            ]);

        return new LengthAwarePaginator(
            $rows->forPage($page, $recordsPerPage)->values(),
            $rows->count(),
            $recordsPerPage,
            $page,
            ['path' => request()->url(), 'pageName' => 'rankingVentasPage'],
        );
    }
}
