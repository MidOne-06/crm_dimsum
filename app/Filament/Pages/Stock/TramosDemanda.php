<?php

namespace App\Filament\Pages\Stock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Models\DirectivaTransferenciaSugerencia;
use App\Models\StockInicialLocal;
use App\Services\DirectivaTransferenciaService;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * "Tramos de Demanda" -- pedido explícito del usuario tras varias vueltas
 * de preguntas sobre a qué período exacto corresponde el Tramo 1 y el
 * Tramo 2 de un local puntual (ver DirectivaTransferenciaService, docblock
 * de la clase, para el porqué de los 2 tramos). Hasta ahora esa
 * información -- las fechas/horas reales que arman cada tramo -- solo se
 * podía calcular a mano (Reflection contra proximaLlegada()/horaLlegada());
 * esta pantalla la hace visible sin escribir código cada vez.
 *
 * Un local a la vez (el período depende de SU horario y SUS días sin DT,
 * no tiene sentido mezclarlo con otros locales en una sola tabla) --
 * siempre muestra la última corrida calculada para ese local.
 */
class TramosDemanda extends Page implements HasTable
{
    use InteractsWithTable;
    use ScopesLocalsToUser;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';
    protected static ?string $navigationLabel = 'Tramos de Demanda';
    protected static ?string $title = 'Tramos de Demanda';
    protected static string|\UnitEnum|null $navigationGroup = 'Stock Inicial';
    protected static ?int $navigationSort = 6;
    protected static ?string $slug = 'stock-inicial/tramos-demanda';
    protected string $view = 'filament.pages.stock.tramos-demanda';

    public ?string $localId = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('directiva-transferencia.view');
    }

    public function mount(): void
    {
        $opciones = $this->localesOptions();
        $this->localId = array_key_first($opciones);
    }

    /** @return array<string, string> */
    private function localesOptions(): array
    {
        return $this->scopeKeyedLocalsToUser(
            StockInicialLocal::where('estado', 'confirmado')->orderBy('local_nombre')->pluck('local_nombre', 'local_id')->all(),
        );
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema([
            Select::make('localId')
                ->label('Local')
                ->hiddenLabel()
                ->options(fn (): array => $this->localesOptions())
                ->searchable()
                ->live()
                ->required(),
        ]);
    }

    public function updatedLocalId(): void
    {
        if ($this->localId && ! $this->localAllowedForUser($this->localId)) {
            $this->localId = null;
        }

        $this->resetTable();
    }

    /**
     * `localId` es una propiedad pública de Livewire -- un usuario
     * restringido a ciertos locales podría editar el payload wire:model y
     * pedir uno fuera de su alcance. `localesOptions()` ya filtra qué se
     * OFRECE en el Select, pero eso por sí solo no protege nada (ver
     * docblock de ScopesLocalsToUser::localAllowedForUser()) -- hay que
     * validar acá el valor efectivamente recibido antes de usarlo.
     */
    private function ultimoCalculadoEn(): ?string
    {
        if (! $this->localId || ! $this->localAllowedForUser($this->localId)) {
            return null;
        }

        return DirectivaTransferenciaSugerencia::where('local_id', $this->localId)->max('calculado_en');
    }

    /** @return array{tramo1_inicio: Carbon, tramo1_fin: Carbon, tramo2_inicio: Carbon, tramo2_fin: Carbon}|null */
    public function periodos(): ?array
    {
        if (! $this->localId || ! $this->localAllowedForUser($this->localId)) {
            return null;
        }

        $calculadoEn = $this->ultimoCalculadoEn();
        $ahora = $calculadoEn ? Carbon::parse($calculadoEn) : null;

        return app(DirectivaTransferenciaService::class)->periodosParaLocal($this->localId, $ahora);
    }

    public function ultimoCalculoHace(): ?string
    {
        $calculadoEn = $this->ultimoCalculadoEn();

        return $calculadoEn ? Carbon::parse($calculadoEn)->diffForHumans() : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function (): Builder {
                if (! $this->localId || ! $this->localAllowedForUser($this->localId)) {
                    return DirectivaTransferenciaSugerencia::query()->whereRaw('1 = 0');
                }

                return DirectivaTransferenciaSugerencia::query()
                    ->where('local_id', $this->localId)
                    ->where('calculado_en', $this->ultimoCalculadoEn());
            })
            ->defaultSort('item_nombre')
            ->paginated(false)
            ->columns([
                TextColumn::make('item_codigo')->label('SKU'),
                TextColumn::make('item_nombre')->label('Producto')->searchable()->wrap(),
                TextColumn::make('demanda_ventana1')->label('Demanda Tramo 1')->numeric(1)->sortable(),
                TextColumn::make('demanda_tramo2')->label('Demanda Tramo 2')->numeric(1)
                    ->getStateUsing(fn (DirectivaTransferenciaSugerencia $record): float => $record->demanda_promedio - $record->demanda_ventana1),
                TextColumn::make('saldo_actual')->label('Saldo actual')->numeric(1),
                TextColumn::make('cantidad_en_transito')->label('En tránsito')->numeric(1),
                TextColumn::make('cantidad_sugerida')->label('Sugerida')->numeric(0)->weight('bold'),
                TextColumn::make('riesgo_quiebre')->label('¿Riesgo de quiebre?')->badge()
                    ->formatStateUsing(fn ($state): string => $state ? 'Quiebre antes de mañana' : 'Sin riesgo')
                    ->color(fn ($state): string => $state ? 'danger' : 'gray'),
            ])
            ->emptyStateHeading('No hay una corrida calculada para este local todavía.');
    }
}
