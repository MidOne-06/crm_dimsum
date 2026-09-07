<?php

namespace App\Filament\Pages\Stock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Models\DirectivaTransferenciaSugerencia;
use App\Services\DirectivaTransferenciaService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Cantidad sugerida a despachar -- fase inicial de la Directiva de
 * Transferencia. Revisión completa a propósito (no por excepción todavía):
 * recién estamos empezando, hay que confirmar que el modelo acierta antes
 * de pasar a que solo se resalten los casos raros. Ver
 * DirectivaTransferenciaService para el criterio de cálculo.
 */
class DirectivaTransferenciaConsolidado extends Page implements HasTable
{
    use InteractsWithTable;
    use ScopesLocalsToUser;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';
    protected static ?string $navigationLabel = 'Directiva de transferencia';
    protected static ?string $title = 'Directiva de Transferencia -- Cantidad sugerida';
    protected static string|\UnitEnum|null $navigationGroup = 'Stock Inicial';
    protected static ?int $navigationSort = 5;
    protected static ?string $slug = 'stock-inicial/directiva-transferencia';
    protected string $view = 'filament.pages.stock.directiva-transferencia-consolidado';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('directiva-transferencia.view');
    }

    private function tableHeaderActions(): array
    {
        return [
            Action::make('recalcular')
                ->label('Recalcular para hoy')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('¿Recalcular la Directiva de Transferencia de hoy?')
                ->modalDescription('Vuelve a calcular la cantidad sugerida para todos los locales e ítems con la fecha de hoy, usando el saldo y el histórico de ventas más reciente.')
                ->action(function (): void {
                    $total = app(DirectivaTransferenciaService::class)->calcularParaFecha(now()->toDateString());
                    Notification::make()->success()->title('Directiva recalculada')->body("{$total} sugerencias generadas para hoy.")->send();
                    $this->resetTable();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->headerActions($this->tableHeaderActions())
            ->query(function (): Builder {
                $query = DirectivaTransferenciaSugerencia::query()
                    ->where('fecha_despacho', now()->toDateString());

                if (auth()->user()?->isRestrictedToLocals()) {
                    $query->whereIn('local_id', auth()->user()->assignedLocalIds());
                }

                return $query;
            })
            ->columns([
                TextColumn::make('local_nombre')->label('Local')->searchable()->sortable(),
                TextColumn::make('item_codigo')->label('SKU')->searchable(),
                TextColumn::make('item_nombre')->label('Producto')->searchable()->wrap(),
                TextColumn::make('demanda_promedio')->label('Demanda prom.')->numeric(2)->alignEnd()
                    ->tooltip(fn (DirectivaTransferenciaSugerencia $r): string => 'Promedio de los últimos '.$r->semanas_consideradas.' '.Carbon::parse($r->fecha_despacho)->locale('es')->isoFormat('dddd').' con venta real.')
                    ->color(fn (DirectivaTransferenciaSugerencia $r): string => $r->esConfianzaBaja() ? 'gray' : 'success'),
                TextColumn::make('semanas_consideradas')->label('Semanas')->alignEnd()->toggleable()
                    ->badge()->color(fn (DirectivaTransferenciaSugerencia $r): string => $r->esConfianzaBaja() ? 'warning' : 'gray'),
                TextColumn::make('saldo_actual')->label('Saldo actual')->numeric(2)->alignEnd()
                    ->color(fn ($state): string => (float) $state < 0 ? 'danger' : 'gray'),
                TextColumn::make('multiplo_aplicado')->label('Múltiplo')->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cantidad_sugerida')->label('Cantidad sugerida')->numeric()->alignEnd()->sortable()
                    ->weight('bold')->color('primary')->badge(),
            ])
            ->filters([
                SelectFilter::make('local_id')->label('Local')
                    ->options(fn (): array => $this->scopeKeyedLocalsToUser(
                        DirectivaTransferenciaSugerencia::query()->where('fecha_despacho', now()->toDateString())
                            ->distinct()->pluck('local_nombre', 'local_id')->all(),
                    ))->searchable(),
                Filter::make('confianza_baja')->label('Confianza baja (< 3 semanas de histórico)')
                    ->query(fn (Builder $query): Builder => $query->where('semanas_consideradas', '<', 3)),
            ])
            ->defaultSort('cantidad_sugerida', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('Todavía no se calculó la Directiva de Transferencia de hoy -- usa "Recalcular para hoy".');
    }
}
