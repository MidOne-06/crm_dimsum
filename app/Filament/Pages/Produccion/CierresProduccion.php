<?php

namespace App\Filament\Pages\Produccion;

use App\Models\ProduccionDiariaCierre;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class CierresProduccion extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationLabel = 'Cierres de producción';
    protected static ?string $title = 'Cierres de producción';
    protected static string|\UnitEnum|null $navigationGroup = 'Producción';
    protected static ?int $navigationSort = 4;
    protected static ?string $slug = 'produccion/cierres';
    protected string $view = 'filament.pages.produccion.cierres-produccion';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->hasPermission('produccion-diaria.view') || $user?->hasPermission('produccion-diaria.registrar')
            || $user?->hasPermission('produccion-diaria.registrar-tanda') || $user?->hasPermission('produccion-diaria.aprobar'));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => ProduccionDiariaCierre::query()
                ->with('creador')
                ->withCount([
                    'tandas',
                    'salidas',
                    'detalles',
                    'detalles as detalles_con_fisico_count' => fn (Builder $query): Builder => $query->whereNotNull('stock_final'),
                ]))
            ->columns([
                Tables\Columns\TextColumn::make('fecha')->label('Fecha')->date('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('estado')->label('Estado')->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'borrador' => 'Borrador', 'enviado' => 'Enviado', 'aprobado' => 'Aprobado', default => 'Nuevo',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'borrador' => 'gray', 'enviado' => 'warning', 'aprobado' => 'success', default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('tandas_count')->label('Bachs')->alignEnd(),
                Tables\Columns\TextColumn::make('salidas_count')->label('Salidas')->alignEnd(),
                Tables\Columns\TextColumn::make('fisico')->label('Físico')->alignEnd()
                    ->state(fn (ProduccionDiariaCierre $record): string => "{$record->detalles_con_fisico_count}/{$record->detalles_count}"),
                Tables\Columns\TextColumn::make('creador.name')->label('Registrado por')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('updated_at')->label('Actualizado')->dateTime('d/m H:i')->sortable()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('estado')->label('Estado')->options([
                    'borrador' => 'Borrador', 'enviado' => 'Enviado', 'aprobado' => 'Aprobado',
                ]),
                Tables\Filters\Filter::make('fecha')->label('Fecha')
                    ->schema([
                        Grid::make(['default' => 1, 'sm' => 2])->schema([
                            DatePicker::make('desde')->label('Desde')->native(false),
                            DatePicker::make('hasta')->label('Hasta')->native(false),
                        ]),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['desde'] ?? null, fn (Builder $q, string $fecha): Builder => $q->whereDate('fecha', '>=', $fecha))
                        ->when($data['hasta'] ?? null, fn (Builder $q, string $fecha): Builder => $q->whereDate('fecha', '<=', $fecha)))
                    ->indicateUsing(function (array $data): array {
                        return collect(['desde' => 'Desde', 'hasta' => 'Hasta'])
                            ->filter(fn (string $label, string $key): bool => filled($data[$key] ?? null))
                            ->map(fn (string $label, string $key): string => $label.' '.Carbon::parse($data[$key])->format('d/m/Y'))
                            ->values()
                            ->all();
                    }),
            ])
            ->defaultSort('fecha', 'desc')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->recordUrl(fn (ProduccionDiariaCierre $record): string => RegistroProduccionDiaria::getUrl(['fecha' => $record->fecha->toDateString()]))
            ->emptyStateHeading('Sin cierres.');
    }
}
