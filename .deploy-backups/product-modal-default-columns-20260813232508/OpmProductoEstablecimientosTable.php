<?php

namespace App\Livewire;

use App\Models\OpmPrecio;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

class OpmProductoEstablecimientosTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public string $productoId;

    public function mount(string $productoId): void
    {
        $this->productoId = $productoId;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => OpmPrecio::query()
                ->where('producto_id', $this->productoId)
                ->orderBy('nombre_comercial')
                ->orderBy('cod_estab'))
            ->columns([
                Tables\Columns\TextColumn::make('cod_estab')
                    ->label('Cód. Estab.')
                    ->placeholder('—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('nombre_comercial')
                    ->label('Nombre comercial')
                    ->placeholder('—')
                    ->limit(34)
                    ->tooltip(fn (OpmPrecio $record): ?string => $record->nombre_comercial)
                    ->searchable(),
                Tables\Columns\TextColumn::make('direccion')
                    ->label('Dirección')
                    ->placeholder('—')
                    ->limit(42)
                    ->tooltip(fn (OpmPrecio $record): ?string => $record->direccion),
                Tables\Columns\TextColumn::make('telefono')
                    ->label('Teléfono')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('departamento')
                    ->label('Departamento')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('provincia')
                    ->label('Provincia')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('distrito')
                    ->label('Distrito')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('fecha')
                    ->label('Fecha Reg.')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('Sin establecimientos registrados')
            ->emptyStateIcon('heroicon-o-building-storefront')
            ->striped();
    }

    public function render(): View
    {
        return view('livewire.opm-producto-establecimientos-table');
    }
}
