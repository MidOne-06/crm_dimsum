<?php

namespace App\Filament\Pages\Stock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Models\StockSaldoActual;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pantalla central de todo el módulo: saldo en tiempo real por local x
 * ítem, calculado como stock inicial + ajustes + Kardex desde la fecha de
 * carga (ver StockSaldoRecalculadorService). No lee nada de "Stock Actual"
 * (Cuadre) -- esta es la fuente que alimenta la Directiva de Transferencia.
 */
class StockSaldoConsolidado extends Page implements HasTable
{
    use InteractsWithTable;
    use ScopesLocalsToUser;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-scale';
    protected static ?string $navigationLabel = 'Consolidado en tiempo real';
    protected static ?string $title = 'Stock en tiempo real -- Consolidado';
    protected static string|\UnitEnum|null $navigationGroup = 'Stock Inicial';
    protected static ?int $navigationSort = 3;
    protected static ?string $slug = 'stock-inicial/consolidado';
    protected string $view = 'filament.pages.stock.stock-saldo-consolidado';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('stock-inicial.view');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function (): Builder {
                $query = StockSaldoActual::query();

                if (auth()->user()?->isRestrictedToLocals()) {
                    $query->whereIn('local_id', auth()->user()->assignedLocalIds());
                }

                return $query;
            })
            ->columns([
                TextColumn::make('local_nombre')->label('Local')->searchable()->sortable(),
                TextColumn::make('item_codigo')->label('Cód.')->searchable(),
                TextColumn::make('item_nombre')->label('Ítem')->searchable()->wrap(),
                TextColumn::make('item_tipo')->label('Tipo')->badge()->color('gray')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('unidad')->label('Unidad'),
                TextColumn::make('cantidad_inicial')->label('Stock inicial')->numeric(4)->alignEnd()->toggleable(),
                TextColumn::make('ajustes_acumulados')->label('Ajustes')->numeric(4)->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('saldo')->label('Saldo actual')->numeric(4)->alignEnd()->sortable()
                    ->weight('bold')
                    ->color(fn (StockSaldoActual $record): string => $record->saldo < 0 ? 'danger' : 'success'),
                TextColumn::make('kardex_actualizado_hasta')->label('Kardex al')->dateTime('d/m/Y H:i')->sortable()
                    ->badge()
                    ->color(fn (StockSaldoActual $record): string => $record->estaDesactualizado() ? 'danger' : 'success')
                    ->formatStateUsing(fn (StockSaldoActual $record): string => $record->kardex_actualizado_hasta
                        ? $record->kardex_actualizado_hasta->format('d/m/Y H:i').($record->estaDesactualizado() ? ' -- desactualizado' : '')
                        : 'Sin extracción todavía'),
            ])
            ->filters([
                SelectFilter::make('local_id')->label('Local')
                    ->options(fn (): array => $this->scopeKeyedLocalsToUser(
                        StockSaldoActual::query()->distinct()->pluck('local_nombre', 'local_id')->all(),
                    ))->searchable(),
                TernaryFilter::make('saldo_negativo')->label('Saldo negativo')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('saldo', '<', 0),
                        false: fn (Builder $query): Builder => $query->where('saldo', '>=', 0),
                    ),
            ])
            ->defaultSort('local_nombre')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('Todavía no hay ningún local con stock inicial confirmado.');
    }
}
