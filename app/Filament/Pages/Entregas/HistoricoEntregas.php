<?php

namespace App\Filament\Pages\Entregas;

use App\Filament\Resources\LocalTransportistaResource;
use App\Models\EntregaDespacho;
use App\Models\StockInicialLocal;
use Filament\Pages\Page;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Histórico de entregas de despacho + promedio de hora de entrega por
 * local x día de semana. Ese promedio NO alimenta el DT todavía (sigue en
 * 12:00 por defecto) -- es solo para análisis operativo, ver el docblock
 * de RegistrarEntrega.
 */
class HistoricoEntregas extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';
    protected static ?string $navigationLabel = 'Histórico de entregas';
    protected static ?string $title = 'Histórico de entregas';
    protected static string|\UnitEnum|null $navigationGroup = 'Entregas';
    protected static ?int $navigationSort = 12;
    protected static ?string $slug = 'entregas/historico';
    protected string $view = 'filament.pages.entregas.historico-entregas';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('entregas.historico.view');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(EntregaDespacho::query())
            ->columns([
                TextColumn::make('fecha_hora')->label('Fecha y hora')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('dia_semana')->label('Día')
                    ->formatStateUsing(fn ($state): string => EntregaDespacho::DIAS[$state] ?? (string) $state)
                    ->badge()->color('gray'),
                TextColumn::make('local_nombre')->label('Local')->searchable()->sortable(),
                TextColumn::make('transportista.name')->label('Transportista')->searchable()->sortable(),
                TextColumn::make('rol_entrega')->label('Rol')
                    ->formatStateUsing(fn ($state): string => ucfirst((string) $state))
                    ->badge()->color(fn ($state): string => $state === 'suplente' ? 'warning' : 'success'),
                TextColumn::make('motivo_reemplazo')->label('Motivo reemplazo')->placeholder('--')->toggleable(),
                ImageColumn::make('foto_path')->label('Foto')->disk('public')->square()->toggleable(),
                TextColumn::make('observacion')->label('Observación')->wrap()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('fecha_hora', 'desc')
            ->filters([
                SelectFilter::make('user_id')->label('Transportista')
                    ->options(fn (): array => LocalTransportistaResource::transportistaOptions())->searchable(),
                SelectFilter::make('local_id')->label('Local')
                    ->options(fn (): array => StockInicialLocal::where('estado', 'confirmado')->orderBy('local_nombre')->pluck('local_nombre', 'local_id')->all())
                    ->searchable(),
                SelectFilter::make('dia_semana')->label('Día de la semana')->options(EntregaDespacho::DIAS),
                Filter::make('reemplazos')->label('Solo reemplazos')->query(fn (Builder $q) => $q->where('es_reemplazo', true)),
            ])
            ->paginated([25, 50, 100])
            ->emptyStateHeading('Todavía no hay entregas registradas.');
    }

    /**
     * Promedio de hora de entrega por local x día de semana, sobre TODO el
     * histórico. Se muestra como grilla en la vista.
     *
     * @return array{locales: array<int, string>, dias: array<int, string>, celdas: array<string, string>}
     */
    public function promedioPorLocalYDia(): array
    {
        $filas = EntregaDespacho::query()
            ->selectRaw("local_nombre, dia_semana, AVG(EXTRACT(EPOCH FROM fecha_hora::time)) AS segundos, COUNT(*) AS n")
            ->groupBy('local_nombre', 'dia_semana')
            ->get();

        $locales = $filas->pluck('local_nombre')->unique()->sort()->values()->all();
        $celdas = [];
        foreach ($filas as $fila) {
            $segundos = (int) round((float) $fila->segundos);
            $h = intdiv($segundos, 3600);
            $m = intdiv($segundos % 3600, 60);
            $celdas["{$fila->local_nombre}|{$fila->dia_semana}"] = sprintf('%02d:%02d (%d)', $h, $m, $fila->n);
        }

        return ['locales' => $locales, 'dias' => EntregaDespacho::DIAS, 'celdas' => $celdas];
    }
}
