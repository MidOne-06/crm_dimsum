<?php

namespace App\Filament\Pages\Stock;

use App\Models\CanjeGuiaCantidadAuditoria;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Trazabilidad de cada edición real de cantidad hecha durante un canje de
 * guías internas -- pedido explícito del usuario (2026-09-13): al cerrar el
 * hueco de "sin tope superior" en `applyGuideQuantityOverrides` (API-TI), el
 * usuario confirmó que recibir MÁS cantidad de la que trae la guía original
 * es un caso real de su operación, así que no hay que bloquearlo -- lo que
 * sí pidió es dejar auditoría real de quién editó qué, cuándo y de cuánto a
 * cuánto. Ver `CanjeGuiaCantidadAuditoria` y el docblock de su migración.
 */
class AuditoriaCantidadesCanje extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationLabel = 'Auditoría de cantidades';
    protected static ?string $title = 'Auditoría de cantidades editadas en canje';
    protected static string|\UnitEnum|null $navigationGroup = 'Guías internas';
    protected static ?int $navigationSort = 14;
    protected static ?string $slug = 'guias-internas/auditoria-cantidades';
    protected string $view = 'filament.pages.stock.auditoria-cantidades-canje';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('movimientos-almacenes.crear');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => CanjeGuiaCantidadAuditoria::query()->with('usuario'))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Cuándo')->dateTime('d/m/Y H:i:s')->sortable(),
                Tables\Columns\TextColumn::make('usuario.name')->label('Usuario')->default('—'),
                Tables\Columns\TextColumn::make('origen')->label('Flujo')->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'individual' => 'Canje individual',
                        'masivo_seleccion' => 'Canje masivo (selección)',
                        'masivo_filtrado' => 'Canjear todo lo filtrado',
                        default => $state,
                    })
                    ->color(fn (string $state): string => $state === 'individual' ? 'gray' : 'info'),
                Tables\Columns\TextColumn::make('guia_ids')->label('Guía(s)')
                    ->formatStateUsing(fn (?array $state): string => $state ? '#'.implode(', #', $state) : '—')
                    ->wrap(),
                Tables\Columns\TextColumn::make('item_codigo')->label('Cód.'),
                Tables\Columns\TextColumn::make('item_descripcion')->label('Ítem')->wrap(),
                Tables\Columns\TextColumn::make('cantidad_original')->label('Cant. original')->numeric(4)->alignEnd(),
                Tables\Columns\TextColumn::make('cantidad_confirmada')->label('Cant. confirmada')->numeric(4)->alignEnd()
                    ->color(fn (CanjeGuiaCantidadAuditoria $r): string => $r->cantidad_confirmada > $r->cantidad_original ? 'warning' : 'danger')
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('diferencia')->label('Diferencia')->alignEnd()
                    ->state(fn (CanjeGuiaCantidadAuditoria $r): string => ($r->cantidad_confirmada > $r->cantidad_original ? '+' : '').number_format((float) $r->cantidad_confirmada - (float) $r->cantidad_original, 4))
                    ->tooltip('Positivo = se confirmó más de lo que traía la guía. Negativo = se confirmó menos (merma/faltante).'),
                Tables\Columns\TextColumn::make('movimiento_id')->label('Movimiento')->default('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('origen')->label('Flujo')->options([
                    'individual' => 'Canje individual',
                    'masivo_seleccion' => 'Canje masivo (selección)',
                    'masivo_filtrado' => 'Canjear todo lo filtrado',
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('Sin ediciones de cantidad registradas todavía.')
            ->emptyStateDescription('Esta tabla solo registra canjes donde alguien editó la cantidad -- un canje normal, sin ediciones, no genera ninguna fila acá.');
    }
}
