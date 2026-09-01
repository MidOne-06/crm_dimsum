<?php

namespace App\Livewire\Kardex;

use App\Models\KardexExtraccion;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\TableComponent;
use Filament\Tables\Table;

class ExtraccionHistorialTable extends TableComponent
{
    public function mount(): void
    {
        abort_unless(auth()->user()?->hasPermission('kardex.extraccion.view'), 403);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(KardexExtraccion::query())
            ->columns([
                TextColumn::make('id')
                    ->label('Cód.')
                    ->sortable(),
                TextColumn::make('rango')
                    ->label('Rango')
                    ->state(fn (KardexExtraccion $record): string => ($record->filtros['fechaInicio'] ?? '—').' al '.($record->filtros['fechaFin'] ?? '—')),
                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state)))
                    ->color(fn (string $state): string => match ($state) {
                        'completado' => 'success',
                        'fallido' => 'danger',
                        'cancelado' => 'gray',
                        default => 'warning',
                    })
                    ->searchable()
                    ->sortable(),
                TextColumn::make('locales')
                    ->label('Locales')
                    ->state(fn (KardexExtraccion $record): string => $record->locales_procesados.' / '.($record->locales_total ?? '—'))
                    ->alignEnd(),
                TextColumn::make('movimientos_guardados')
                    ->label('Movimientos')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('locales_fallidos')
                    ->label('Fallidos')
                    ->numeric()
                    ->color('danger')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('duracion')
                    ->label('Duración'),
                TextColumn::make('iniciado_at')
                    ->label('Iniciado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options([
                        'pendiente' => 'Pendiente',
                        'en_progreso' => 'En progreso',
                        'completado' => 'Completado',
                        'fallido' => 'Fallido',
                        'cancelado' => 'Cancelado',
                    ]),
            ])
            ->defaultSort('id', 'desc')
            ->searchable()
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('Todavía no se ha corrido ninguna extracción.');
    }

    public function render()
    {
        return view('livewire.kardex.extraccion-historial-table');
    }
}
