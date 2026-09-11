<?php

namespace App\Filament\Pages\Stock;

use App\Models\LocalActivoOverride;
use App\Models\StockInicialLocal;
use App\Services\DirectivaTransferenciaService;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * "Locales Activos" -- pedido explícito del usuario tras el incidente real
 * del 2026-09-11: una corrida de "Iniciar Directiva de Transferencia" con
 * "Todos, excepto..." usada al revés dejó cantidades reales de despacho
 * calculadas para 4 tiendas cerradas (ver bitácora de ese día).
 *
 * Reemplaza la opción ciega "Venta activa (3 días)" del radio de alcance
 * por una lista visible: para cada local muestra si Kardex lo ve activo
 * (venta real de un ítem de despacho en los últimos 3 días -- dinámico,
 * viene de datos sincronizados con Restaurant cada 30 min) y desde cuándo
 * no vende si está inactivo, y permite FORZAR el estado cuando el
 * automático no calza con la realidad operativa (ver docblock de
 * `DirectivaTransferenciaService::localesActivos()` para el porqué no se
 * usa el flag `es_venta` de Restaurant -- probado en vivo, no es confiable).
 *
 * Esta pantalla es la fuente de verdad; "Iniciar Directiva de
 * Transferencia" > "Locales activos" consume exactamente este mismo
 * cálculo (`localesActivos()`), nunca un criterio distinto.
 */
class LocalesActivos extends Page implements HasTable
{
    use InteractsWithTable;

    /**
     * Las 3 columnas dinámicas de abajo llamaban a `DirectivaTransferenciaService`
     * una vez POR FILA con un array de un solo local -- con 32 locales
     * confirmados, hasta ~96 consultas separadas contra `kardex_movimientos`
     * (millones de filas) en cada carga de la tabla, cuando los propios
     * métodos ya aceptan el array completo. Corregido 2026-09-12 (barrida
     * de huecos funcionales): se calculan las 3 listas UNA sola vez por
     * request y las columnas solo consultan estos arrays en memoria.
     *
     * @var array{ultimaVenta: array<string, ?\Illuminate\Support\Carbon>, automaticos: array<int, string>, efectivos: array<int, string>}|null
     */
    private ?array $datosLocalesCache = null;

    /** @return array{ultimaVenta: array<string, ?\Illuminate\Support\Carbon>, automaticos: array<int, string>, efectivos: array<int, string>} */
    private function datosLocales(): array
    {
        if ($this->datosLocalesCache !== null) {
            return $this->datosLocalesCache;
        }

        $localesIds = StockInicialLocal::where('estado', 'confirmado')->pluck('local_id')->all();
        $service = app(DirectivaTransferenciaService::class);

        return $this->datosLocalesCache = [
            'ultimaVenta' => $service->ultimaVentaDespachoPorLocal($localesIds),
            'automaticos' => $service->localesConVentaActiva($localesIds),
            'efectivos' => $service->localesActivos($localesIds),
        ];
    }

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-signal';
    protected static ?string $navigationLabel = 'Locales activos';
    protected static ?string $title = 'Locales activos';
    protected static string|\UnitEnum|null $navigationGroup = 'Stock Inicial';
    protected static ?int $navigationSort = 3;
    protected static ?string $slug = 'stock-inicial/locales-activos';
    protected string $view = 'filament.pages.stock.locales-activos';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('directiva-transferencia.view');
    }

    private function puedeGestionar(): bool
    {
        return (bool) auth()->user()?->hasPermission('directiva-transferencia.locales-activos.manage');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => StockInicialLocal::query()->where('estado', 'confirmado'))
            ->defaultSort('local_nombre')
            ->paginated(false)
            ->columns([
                TextColumn::make('local_nombre')->label('Local')->searchable()->weight('medium'),
                TextColumn::make('ultima_venta')->label('Última venta de despacho')
                    ->getStateUsing(function (StockInicialLocal $record): string {
                        $fecha = $this->datosLocales()['ultimaVenta'][$record->local_id] ?? null;

                        return $fecha ? $fecha->diffForHumans() : 'nunca';
                    }),
                TextColumn::make('automatico')->label('Automático (Kardex)')->badge()
                    ->getStateUsing(fn (StockInicialLocal $record): string => in_array($record->local_id, $this->datosLocales()['automaticos'], true) ? 'Activo' : 'Inactivo')
                    ->color(fn (string $state): string => $state === 'Activo' ? 'success' : 'gray'),
                TextColumn::make('override')->label('Forzado manualmente')->badge()
                    ->getStateUsing(function (StockInicialLocal $record): string {
                        $override = LocalActivoOverride::where('local_id', $record->local_id)->first();
                        if (! $override) {
                            return '-- (automático)';
                        }

                        return $override->activo ? 'Forzado activo' : 'Forzado inactivo';
                    })
                    ->color(fn (string $state): string => str_contains($state, 'Forzado') ? 'warning' : 'gray'),
                TextColumn::make('efectivo')->label('Usado por la Directiva')->badge()
                    ->getStateUsing(fn (StockInicialLocal $record): string => in_array($record->local_id, $this->datosLocales()['efectivos'], true) ? 'Activo' : 'Inactivo')
                    ->color(fn (string $state): string => $state === 'Activo' ? 'success' : 'danger')
                    ->weight('bold'),
            ])
            ->actions([
                Action::make('cambiarEstado')
                    ->label('Cambiar')
                    ->icon('heroicon-o-pencil-square')
                    ->size('sm')
                    ->visible(fn (): bool => $this->puedeGestionar())
                    ->modalHeading('Forzar estado del local')
                    ->fillForm(function (StockInicialLocal $record): array {
                        $override = LocalActivoOverride::where('local_id', $record->local_id)->first();

                        return [
                            'modo' => $override ? ($override->activo ? 'forzar_activo' : 'forzar_inactivo') : 'automatico',
                            'motivo' => $override?->motivo,
                        ];
                    })
                    ->schema([
                        Radio::make('modo')
                            ->hiddenLabel()
                            ->options([
                                'automatico' => 'Automático (según venta reciente en Kardex)',
                                'forzar_activo' => 'Forzar activo',
                                'forzar_inactivo' => 'Forzar inactivo',
                            ])
                            ->live()
                            ->required(),
                        TextInput::make('motivo')
                            ->label('Motivo')
                            ->maxLength(160)
                            ->visible(fn (callable $get): bool => $get('modo') !== 'automatico'),
                    ])
                    ->action(function (StockInicialLocal $record, array $data): void {
                        abort_unless($this->puedeGestionar(), 403);

                        if ($data['modo'] === 'automatico') {
                            LocalActivoOverride::where('local_id', $record->local_id)->delete();
                            Notification::make()->success()->title('Vuelve al automático')->body("{$record->local_nombre} ya no tiene un estado forzado.")->send();

                            return;
                        }

                        LocalActivoOverride::updateOrCreate(
                            ['local_id' => $record->local_id],
                            [
                                'local_nombre' => $record->local_nombre,
                                'activo' => $data['modo'] === 'forzar_activo',
                                'motivo' => $data['motivo'] ?? null,
                                'actualizado_por' => auth()->id(),
                            ],
                        );
                        Notification::make()->success()->title('Estado forzado')->body("{$record->local_nombre} queda ".($data['modo'] === 'forzar_activo' ? 'activo' : 'inactivo').' hasta que lo vuelvas a cambiar.')->send();
                    }),
            ]);
    }
}
