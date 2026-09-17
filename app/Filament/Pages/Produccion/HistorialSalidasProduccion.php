<?php

namespace App\Filament\Pages\Produccion;

use App\Filament\Concerns\ExportaTablaExcel;
use App\Models\ProduccionDiariaSalida;
use App\Models\ProduccionProducto;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Historial de "Registrar salida" de Producción -- pedido explícito del
 * usuario (2026-09-17): no había ningún lugar para ver salidas de días
 * anteriores, solo "Últimas salidas" (día actual, últimas 8) dentro del
 * registro diario. Independiente de Restaurant, igual que el resto del
 * módulo -- lee directo de produccion_diaria_salidas, nunca de Guías
 * Internas ni de Salidas de Stock (esos son circuitos reales con
 * Restaurant, completamente aparte).
 */
class HistorialSalidasProduccion extends Page implements HasTable
{
    use InteractsWithTable;
    use ExportaTablaExcel;

    protected function getHeaderActions(): array
    {
        return [
            $this->exportarExcelAction(
                'historial-salidas-'.now()->format('Y-m-d').'.xlsx',
                ['Fecha', 'Hora', 'Código', 'Producto', 'Cantidad', 'Unidad', 'Destino', 'Nota', 'Registrado por'],
                fn (ProduccionDiariaSalida $s): array => [
                    $s->cierre?->fecha?->format('d/m/Y'), $s->created_at?->timezone('America/Lima')->format('H:i'),
                    $s->item_codigo, $s->item_nombre, (float) $s->cantidad, $s->unidad,
                    match ($s->destino) { 'despacho' => 'Área de despacho', 'merma' => 'Merma / descarte', 'ajuste' => 'Ajuste de conteo', default => 'Otro' },
                    $s->nota, $s->registrador?->name,
                ],
            ),
        ];
    }

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';
    protected static ?string $navigationLabel = 'Historial de salidas';
    protected static ?string $title = 'Historial de salidas de producción';
    protected static string|\UnitEnum|null $navigationGroup = 'Producción';
    protected static ?int $navigationSort = 5;
    protected static ?string $slug = 'produccion/historial-salidas';
    protected string $view = 'filament.pages.produccion.historial-salidas-produccion';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->hasPermission('produccion-diaria.view') || $user?->hasPermission('produccion-diaria.registrar') || $user?->hasPermission('produccion-diaria.aprobar'));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => ProduccionDiariaSalida::query()->with(['cierre', 'registrador']))
            ->columns([
                Tables\Columns\TextColumn::make('cierre.fecha')->label('Fecha')->date('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->label('Hora')->time('H:i')->sortable(),
                Tables\Columns\TextColumn::make('item_codigo')->label('Código'),
                Tables\Columns\TextColumn::make('item_nombre')->label('Producto')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('cantidad')->label('Cantidad')->numeric(2)->alignEnd(),
                Tables\Columns\TextColumn::make('unidad')->label('Unidad'),
                Tables\Columns\TextColumn::make('destino')->label('Destino')->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'despacho' => 'Área de despacho',
                        'merma' => 'Merma / descarte',
                        'ajuste' => 'Ajuste de conteo',
                        default => 'Otro',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'despacho' => 'success',
                        'merma' => 'danger',
                        'ajuste' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('nota')->label('Nota')->limit(50)
                    ->tooltip(fn (ProduccionDiariaSalida $record): ?string => filled($record->nota) ? $record->nota : null)
                    ->toggleable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('registrador.name')->label('Registrado por')->toggleable()->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('destino')->label('Destino')->options([
                    'despacho' => 'Área de despacho',
                    'merma' => 'Merma / descarte',
                    'ajuste' => 'Ajuste de conteo',
                    'otro' => 'Otro',
                ]),
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
            ->emptyStateHeading('Sin salidas registradas.');
    }
}
