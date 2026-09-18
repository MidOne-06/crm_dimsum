<?php

namespace App\Filament\Pages\Produccion;

use App\Filament\Concerns\ExportaTablaExcel;
use App\Models\ProduccionDiariaTanda;
use App\Models\ProduccionProducto;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Historial de "Registrar tanda" de Producción -- pedido explícito del
 * usuario (2026-09-17): necesita saber cuántas tandas hubo por producto
 * en el día (hasta el cierre físico) y a qué hora fue cada una. "Últimas
 * tandas" en el registro diario solo muestra las últimas 8 del día
 * actual, sin filtro por producto ni por fecha pasada -- mismo hueco ya
 * resuelto para salidas con HistorialSalidasProduccion, ahora replicado
 * para tandas. Filtrando por producto y fecha, la tabla ya responde
 * "cuántas" (paginación/conteo) y "a qué hora" (columna Hora) para ese
 * producto ese día.
 */
class HistorialTandasProduccion extends Page implements HasTable
{
    use InteractsWithTable;
    use ExportaTablaExcel;

    protected function getHeaderActions(): array
    {
        return [
            $this->exportarExcelAction(
                'historial-bachs-'.now()->format('Y-m-d').'.xlsx',
                ['Fecha', 'Hora', 'Código', 'Producto', 'Cantidad', 'Unidad', 'Nota', 'Registrado por'],
                fn (ProduccionDiariaTanda $t): array => [
                    $t->cierre?->fecha?->format('d/m/Y'), $t->created_at?->timezone('America/Lima')->format('H:i'),
                    $t->item_codigo, $t->item_nombre, (float) $t->cantidad, $t->unidad, $t->nota, $t->registrador?->name,
                ],
            ),
        ];
    }

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';
    protected static ?string $navigationLabel = 'Historial de bachs';
    protected static ?string $title = 'Historial de bachs de producción';
    protected static string|\UnitEnum|null $navigationGroup = 'Producción';
    protected static ?int $navigationSort = 6;
    protected static ?string $slug = 'produccion/historial-tandas';
    protected string $view = 'filament.pages.produccion.historial-tandas-produccion';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->hasPermission('produccion-diaria.view') || $user?->hasPermission('produccion-diaria.registrar') || $user?->hasPermission('produccion-diaria.aprobar'));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => ProduccionDiariaTanda::query()->with(['cierre', 'registrador']))
            ->columns([
                Tables\Columns\TextColumn::make('cierre.fecha')->label('Fecha')->date('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->label('Hora')->time('H:i')->sortable(),
                Tables\Columns\TextColumn::make('item_codigo')->label('Código'),
                Tables\Columns\TextColumn::make('item_nombre')->label('Producto')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('cantidad')->label('Cantidad')->numeric(2)->alignEnd(),
                Tables\Columns\TextColumn::make('unidad')->label('Unidad'),
                Tables\Columns\TextColumn::make('nota')->label('Nota')->limit(50)
                    ->tooltip(fn (ProduccionDiariaTanda $record): ?string => filled($record->nota) ? $record->nota : null)
                    ->toggleable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('registrador.name')->label('Registrado por')->toggleable()->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('producto_id')->label('Producto')
                    ->options(fn (): array => ProduccionProducto::query()->orderBy('nombre')->pluck('nombre', 'id')->all())
                    ->searchable(),
                Tables\Filters\Filter::make('fecha')->label('Rango de fechas')
                    ->schema([
                        Grid::make(2)->schema([
                            DatePicker::make('desde')->label('Desde')->native(false),
                            DatePicker::make('hasta')->label('Hasta')->native(false),
                        ]),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['desde'] ?? null, fn (Builder $q, $desde) => $q->whereHas('cierre', fn (Builder $c) => $c->whereDate('fecha', '>=', $desde)))
                            ->when($data['hasta'] ?? null, fn (Builder $q, $hasta) => $q->whereHas('cierre', fn (Builder $c) => $c->whereDate('fecha', '<=', $hasta)));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicadores = [];
                        if ($data['desde'] ?? null) $indicadores[] = 'Desde '.\Illuminate\Support\Carbon::parse($data['desde'])->format('d/m/Y');
                        if ($data['hasta'] ?? null) $indicadores[] = 'Hasta '.\Illuminate\Support\Carbon::parse($data['hasta'])->format('d/m/Y');

                        return $indicadores;
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('Sin bachs registrados.');
    }
}
