<?php

namespace App\Livewire\Kardex;

use App\Models\KardexExtraccion;
use App\Models\KardexExtraccionLocal;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\TableComponent;
use Filament\Tables\Table;

class ExtraccionLocalesTable extends TableComponent
{
    public int $extraccionId;

    public function mount(int $extraccionId): void
    {
        abort_unless(auth()->user()?->hasPermission('kardex.extraccion.view'), 403);

        $this->extraccionId = $extraccionId;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(KardexExtraccionLocal::query()->where('extraccion_id', $this->extraccionId))
            ->columns([
                TextColumn::make('local_nombre')
                    ->label('Local')
                    ->default(fn (KardexExtraccionLocal $record): string => (string) $record->local_id)
                    ->searchable()
                    ->sortable(),
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
                    ->sortable(),
                TextColumn::make('movimientos_guardados')
                    ->label('Movimientos')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('mensaje_error')
                    ->label('Detalle / error')
                    ->placeholder('—')
                    ->color(fn (?string $state): ?string => filled($state) ? 'danger' : null)
                    ->wrap(),
            ])
            ->defaultSort('local_nombre')
            ->searchable()
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->poll(fn (): ?string => $this->isProcessing() ? '3s' : null)
            ->emptyStateHeading('No se generaron locales para esta extracción.');
    }

    protected function isProcessing(): bool
    {
        return KardexExtraccion::query()
            ->whereKey($this->extraccionId)
            ->whereIn('estado', ['pendiente', 'en_progreso'])
            ->exists();
    }

    public function render()
    {
        return view('livewire.kardex.extraccion-locales-table');
    }
}
