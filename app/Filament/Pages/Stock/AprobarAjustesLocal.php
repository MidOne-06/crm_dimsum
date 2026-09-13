<?php

namespace App\Filament\Pages\Stock;

use App\Models\DirectivaAjusteLocalDetalle;
use App\Models\DirectivaTransferenciaSugerencia;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Aprobar ajustes de locales" -- pedido explícito del usuario: revisar los
 * pedidos de ajuste que cada local carga en "Cargar mi sugerido" y decidir,
 * producto por producto O todos los de un local de una vez ("las dos
 * opciones"), si se aplican a la Directiva de Transferencia ya calculada.
 * Al aprobar, el delta (siempre múltiplo del despacho de ese producto) se
 * suma directo a `cantidad_sugerida` en directiva_transferencia_sugerencias
 * -- así todas las pantallas/exports que ya leen esa columna (Consolidado,
 * PDF, Excel) reflejan el ajuste sin que haya que tocarlas. Al rechazar, el
 * comentario queda visible para el local en su propia pantalla.
 */
class AprobarAjustesLocal extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-check-badge';
    protected static ?string $navigationLabel = 'Aprobar ajustes de locales';
    protected static ?string $title = 'Aprobar ajustes de locales';
    protected static string|\UnitEnum|null $navigationGroup = 'Stock Inicial';
    protected static ?int $navigationSort = 8;
    protected static ?string $slug = 'stock-inicial/aprobar-ajustes-locales';
    protected string $view = 'filament.pages.stock.aprobar-ajustes-local';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('directiva-transferencia.ajuste-local.aprobar');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => DirectivaAjusteLocalDetalle::query()->with('solicitud'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('solicitud.local_nombre')->label('Local')->searchable()->sortable(),
                TextColumn::make('solicitud.fecha_despacho')->label('Despacho')->date('d/m/Y')->sortable(),
                TextColumn::make('item_nombre')->label('Producto')->searchable()->wrap(),
                TextColumn::make('sugerida_actual')->label('Sugerida hoy')
                    ->getStateUsing(fn (DirectivaAjusteLocalDetalle $record): string => $this->cantidadActual($record) !== null
                        ? number_format($this->cantidadActual($record), 0)
                        : '-- (sin corrida vigente)'),
                TextColumn::make('multiplos_solicitados')->label('Ajuste pedido')->alignEnd()
                    ->formatStateUsing(fn (int $state): string => ($state > 0 ? '+' : '').$state.' múltiplo(s)'),
                TextColumn::make('delta_unidades')->label('= Unidades')->alignEnd()
                    ->formatStateUsing(fn ($state): string => ($state > 0 ? '+' : '').number_format((float) $state, 0)),
                TextColumn::make('motivo')->label('Motivo')->wrap()->limit(60)->placeholder('--'),
                TextColumn::make('estado')->label('Estado')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'aprobado' => 'success',
                        'rechazado' => 'danger',
                        default => 'warning',
                    }),
            ])
            ->filters([
                SelectFilter::make('estado')->options(['pendiente' => 'Pendiente', 'aprobado' => 'Aprobado', 'rechazado' => 'Rechazado'])->default('pendiente'),
            ])
            ->actions([
                Action::make('aprobar')
                    ->label('Aprobar')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (DirectivaAjusteLocalDetalle $record): bool => $record->estado === 'pendiente')
                    ->requiresConfirmation()
                    ->modalDescription(fn (DirectivaAjusteLocalDetalle $record): string => "Se sumará {$record->delta_unidades} unidades a la cantidad sugerida real de {$record->item_nombre} para {$record->solicitud->local_nombre}.")
                    ->action(fn (DirectivaAjusteLocalDetalle $record) => $this->aprobar(collect([$record]))),
                Action::make('rechazar')
                    ->label('Rechazar')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (DirectivaAjusteLocalDetalle $record): bool => $record->estado === 'pendiente')
                    ->schema([
                        Textarea::make('comentario')->label('Motivo del rechazo')->required()->rows(2)->maxLength(500),
                    ])
                    ->action(fn (DirectivaAjusteLocalDetalle $record, array $data) => $this->rechazar(collect([$record]), $data['comentario'])),
            ])
            ->bulkActions([
                BulkAction::make('aprobarTodos')
                    ->label('Aprobar seleccionados')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records) => $this->aprobar($records->where('estado', 'pendiente'))),
                BulkAction::make('rechazarTodos')
                    ->label('Rechazar seleccionados')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->deselectRecordsAfterCompletion()
                    ->schema([
                        Textarea::make('comentario')->label('Motivo del rechazo')->required()->rows(2)->maxLength(500),
                    ])
                    ->action(fn (Collection $records, array $data) => $this->rechazar($records->where('estado', 'pendiente'), $data['comentario'])),
            ])
            ->emptyStateHeading('No hay ajustes de locales para revisar.');
    }

    private function cantidadActual(DirectivaAjusteLocalDetalle $detalle): ?float
    {
        $sugerencia = $this->sugerenciaVigente($detalle);

        return $sugerencia ? (float) $sugerencia->cantidad_sugerida : null;
    }

    private function sugerenciaVigente(DirectivaAjusteLocalDetalle $detalle): ?DirectivaTransferenciaSugerencia
    {
        $solicitud = $detalle->solicitud;
        $calculadoEn = DirectivaTransferenciaSugerencia::where('local_id', $solicitud->local_id)
            ->where('fecha_despacho', $solicitud->fecha_despacho)
            ->max('calculado_en');

        if (! $calculadoEn) {
            return null;
        }

        return DirectivaTransferenciaSugerencia::where('local_id', $solicitud->local_id)
            ->where('fecha_despacho', $solicitud->fecha_despacho)
            ->where('calculado_en', $calculadoEn)
            ->where('item_id', $detalle->item_id)
            ->where('item_tipo', $detalle->item_tipo)
            ->first();
    }

    /** @param \Illuminate\Support\Collection<int, DirectivaAjusteLocalDetalle> $detalles */
    private function aprobar($detalles): void
    {
        abort_unless(auth()->user()?->hasPermission('directiva-transferencia.ajuste-local.aprobar'), 403);

        $aplicados = 0;
        $sinCorrida = [];

        foreach ($detalles as $detalle) {
            if ($detalle->estado !== 'pendiente') {
                continue;
            }

            $sugerencia = $this->sugerenciaVigente($detalle);
            if (! $sugerencia) {
                $sinCorrida[] = "{$detalle->item_nombre} ({$detalle->solicitud->local_nombre})";

                continue;
            }

            DB::transaction(function () use ($detalle, $sugerencia): void {
                DirectivaTransferenciaSugerencia::whereKey($sugerencia->id)->update([
                    'cantidad_sugerida' => DB::raw('cantidad_sugerida + '.(float) $detalle->delta_unidades),
                    'ajuste_local_unidades' => DB::raw('ajuste_local_unidades + '.(float) $detalle->delta_unidades),
                ]);
                $detalle->update([
                    'estado' => 'aprobado',
                    'comentario_admin' => null,
                    'revisado_por' => auth()->id(),
                    'revisado_en' => now(),
                ]);
            });
            $aplicados++;
        }

        if ($aplicados > 0) {
            Notification::make()->success()->title("{$aplicados} ajuste(s) aprobado(s)")->body('La cantidad sugerida real ya quedó actualizada.')->send();
        }
        if ($sinCorrida) {
            Notification::make()->warning()->title('Algunos no se pudieron aplicar')
                ->body('Ya no hay una corrida vigente para: '.implode(', ', $sinCorrida).'. Pide al local que vuelva a solicitar sobre la Directiva actual.')
                ->send();
        }
    }

    /** @param \Illuminate\Support\Collection<int, DirectivaAjusteLocalDetalle> $detalles */
    private function rechazar($detalles, string $comentario): void
    {
        abort_unless(auth()->user()?->hasPermission('directiva-transferencia.ajuste-local.aprobar'), 403);

        $rechazados = 0;
        foreach ($detalles as $detalle) {
            if ($detalle->estado !== 'pendiente') {
                continue;
            }

            $detalle->update([
                'estado' => 'rechazado',
                'comentario_admin' => $comentario,
                'revisado_por' => auth()->id(),
                'revisado_en' => now(),
            ]);
            $rechazados++;
        }

        if ($rechazados > 0) {
            Notification::make()->success()->title("{$rechazados} ajuste(s) rechazado(s)")->body('El local verá tu comentario y podrá corregir su pedido.')->send();
        }
    }
}
