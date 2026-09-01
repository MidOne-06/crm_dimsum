<?php

namespace App\Livewire\Ventas;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Reactive;
use Livewire\Component;

class DetalleVentaTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    #[Reactive]
    public array $items = [];

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (int $page, int $recordsPerPage): LengthAwarePaginator => $this->records($page, $recordsPerPage))
            ->columns([
                TextColumn::make('descripcion')->label('Ítem')->wrap(),
                TextColumn::make('cantidad')->label('Cantidad')->numeric(3)->alignEnd(),
                TextColumn::make('precio')->label('Precio')->numeric(2)->alignEnd(),
                TextColumn::make('descuento')->label('Descuento')->numeric(2)->alignEnd(),
                TextColumn::make('importe')->label('Importe')->numeric(2)->alignEnd()->weight('medium'),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('Sin ítems.');
    }

    protected function records(int $page, int $recordsPerPage): LengthAwarePaginator
    {
        $items = collect($this->items)
            ->map(fn (array $item): array => [
                'descripcion' => $item['descripcion'] ?? '—',
                'cantidad' => (float) ($item['cantidad'] ?? 0),
                'precio' => (float) ($item['precio'] ?? 0),
                'descuento' => (float) ($item['descuento'] ?? 0),
                'importe' => (float) ($item['importe'] ?? 0),
            ])
            ->values();

        return new LengthAwarePaginator(
            $items->forPage($page, $recordsPerPage)->values(),
            $items->count(),
            $recordsPerPage,
            $page,
            ['path' => request()->url(), 'pageName' => 'detalleVentaPage'],
        );
    }

    public function render()
    {
        return view('livewire.ventas.detalle-venta-table');
    }
}
