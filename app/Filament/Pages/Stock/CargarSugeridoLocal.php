<?php

namespace App\Filament\Pages\Stock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Models\DirectivaAjusteLocalDetalle;
use App\Models\DirectivaAjusteLocalHistorial;
use App\Models\DirectivaAjusteLocalSolicitud;
use App\Models\DirectivaTransferenciaSugerencia;
use App\Models\ProductoPresentacionDespacho;
use App\Models\StockInicialLocal;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Cargar mi sugerido" -- pedido explícito del usuario (2026-09-13): cada
 * local puede pedir un ajuste sobre la cantidad que la Directiva de
 * Transferencia ya calculó para él. El local escribe la cantidad tal cual
 * (4, 13, -7...) -- el campo se autocorrige solo al múltiplo de despacho
 * MÁS CERCANO de ese producto (ver ProductoPresentacionDespacho, mismo
 * múltiplo que usa el propio cálculo para redondear -- pero acá "más
 * cercano", no siempre hacia arriba), nunca queda una cantidad suelta. El
 * administrador aprueba o rechaza cada pedido en "Aprobar ajustes de
 * locales"; mientras siga 'pendiente' (o si se rechazó, con el comentario
 * a la vista), el local puede seguir corrigiendo su número acá mismo.
 */
class CargarSugeridoLocal extends Page implements HasTable
{
    use InteractsWithTable;
    use ScopesLocalsToUser;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-hand-raised';
    protected static ?string $navigationLabel = 'Cargar mi sugerido';
    protected static ?string $title = 'Cargar mi sugerido';
    protected static string|\UnitEnum|null $navigationGroup = 'Stock Inicial';
    protected static ?int $navigationSort = 7;
    protected static ?string $slug = 'stock-inicial/cargar-sugerido';
    protected string $view = 'filament.pages.stock.cargar-sugerido-local';

    public ?string $localId = null;

    /**
     * `solicitudActual()`/`detallePara()` se llaman varias veces POR FILA
     * (3 columnas + 2 puntos de la acción "solicitar") -- sin esta caché,
     * cada llamada volvía a consultar la BD, mismo patrón de N+1 ya
     * encontrado y corregido en "Locales Activos" (2026-09-12). Acá el
     * impacto es bajo (15 productos, tabla chica, no millones de filas de
     * Kardex), pero es la misma causa evitable: una consulta por
     * (solicitud, detalles) alcanza para toda la tabla.
     */
    private ?DirectivaAjusteLocalSolicitud $solicitudCache = null;

    private bool $solicitudCacheCargada = false;

    private bool $ultimoCalculadoEnCargado = false;

    private ?string $ultimoCalculadoEnCache = null;

    /** @var \Illuminate\Support\Collection<string, DirectivaTransferenciaSugerencia>|null */
    private ?\Illuminate\Support\Collection $sugerenciasCache = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('directiva-transferencia.ajuste-local.crear');
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
        // `localId` es una propiedad pública de Livewire -- filtrar las
        // OPCIONES del Select no protege nada por sí solo (ver docblock de
        // ScopesLocalsToUser::localAllowedForUser()); se revalida acá el
        // valor efectivamente recibido antes de usarlo en cualquier query.
        if ($this->localId && ! $this->localAllowedForUser($this->localId)) {
            $this->localId = null;
        }

        $this->resetTable();
    }

    private function ultimoCalculadoEn(): ?string
    {
        if ($this->ultimoCalculadoEnCargado) {
            return $this->ultimoCalculadoEnCache;
        }
        $this->ultimoCalculadoEnCargado = true;

        if (! $this->localId || ! $this->localAllowedForUser($this->localId)) {
            return $this->ultimoCalculadoEnCache = null;
        }

        return $this->ultimoCalculadoEnCache = DirectivaTransferenciaSugerencia::where('local_id', $this->localId)->max('calculado_en');
    }

    public function fechaDespacho(): ?string
    {
        $calculadoEn = $this->ultimoCalculadoEn();
        if (! $calculadoEn) {
            return null;
        }

        // sugerenciasCache ya trae fecha_despacho para las 15 filas -- si
        // todavía no se cargó (primera llamada del render, antes de que la
        // tabla pida algún producto), una sola fila alcanza para leerla.
        if ($this->sugerenciasCache !== null) {
            $fecha = $this->sugerenciasCache->first()?->fecha_despacho;

            return $fecha ? $fecha->toDateString() : null;
        }

        $fecha = DirectivaTransferenciaSugerencia::where('local_id', $this->localId)
            ->where('calculado_en', $calculadoEn)
            ->value('fecha_despacho');

        return $fecha ? (string) $fecha : null;
    }

    private function solicitudActual(): ?DirectivaAjusteLocalSolicitud
    {
        if ($this->solicitudCacheCargada) {
            return $this->solicitudCache;
        }
        $this->solicitudCacheCargada = true;

        $fecha = $this->fechaDespacho();
        if (! $fecha || ! $this->localId || ! $this->localAllowedForUser($this->localId)) {
            return $this->solicitudCache = null;
        }

        return $this->solicitudCache = DirectivaAjusteLocalSolicitud::where('local_id', $this->localId)
            ->where('fecha_despacho', $fecha)
            ->with('detalles')
            ->first();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function (): Builder {
                if (! $this->localId || ! $this->localAllowedForUser($this->localId) || ! $this->fechaDespacho()) {
                    return ProductoPresentacionDespacho::query()->whereRaw('1 = 0');
                }

                return ProductoPresentacionDespacho::query()->where('activo', true);
            })
            ->defaultSort('item_nombre')
            ->paginated(false)
            ->columns([
                TextColumn::make('item_nombre')->label('Producto')->weight('medium')->wrap(),
                TextColumn::make('multiplo')->label('Múltiplo')->numeric()->badge()->color('info'),
                TextColumn::make('sugerida')->label('Sugerida por el sistema')->weight('bold')
                    ->getStateUsing(fn (ProductoPresentacionDespacho $record): string => $this->sugerenciaPara($record)?->cantidad_sugerida !== null
                        ? number_format((float) $this->sugerenciaPara($record)->cantidad_sugerida, 0)
                        : '--'),
                TextColumn::make('mi_solicitud')->label('Mi solicitud')
                    ->getStateUsing(function (ProductoPresentacionDespacho $record): string {
                        $detalle = $this->detallePara($record);
                        if (! $detalle) {
                            return '-- sin solicitud --';
                        }

                        $signo = $detalle->multiplos_solicitados > 0 ? '+' : '';

                        return "{$signo}{$detalle->multiplos_solicitados} múltiplo(s) = {$signo}".number_format((float) $detalle->delta_unidades, 0).' unidades';
                    }),
                TextColumn::make('estado')->label('Estado')->badge()
                    ->getStateUsing(fn (ProductoPresentacionDespacho $record): string => match ($this->detallePara($record)?->estado) {
                        'aprobado' => 'Aprobado',
                        'rechazado' => 'Rechazado',
                        'pendiente' => 'Pendiente de revisión',
                        default => 'Sin solicitud',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Aprobado' => 'success',
                        'Rechazado' => 'danger',
                        'Pendiente de revisión' => 'warning',
                        default => 'gray',
                    })
                    ->description(fn (ProductoPresentacionDespacho $record): ?string => $this->detallePara($record)?->estado === 'rechazado'
                        ? 'Motivo: '.($this->detallePara($record)->comentario_admin ?: 'sin comentario')
                        : null),
            ])
            ->actions([
                Action::make('solicitar')
                    ->label(fn (ProductoPresentacionDespacho $record): string => $this->detallePara($record) ? 'Editar' : 'Solicitar ajuste')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (ProductoPresentacionDespacho $record): bool => $this->detallePara($record)?->estado !== 'aprobado')
                    ->modalHeading(fn (ProductoPresentacionDespacho $record): string => 'Ajuste para '.$record->item_nombre)
                    ->fillForm(function (ProductoPresentacionDespacho $record): array {
                        $detalle = $this->detallePara($record);

                        return [
                            'delta' => $detalle ? (int) $detalle->delta_unidades : null,
                            'motivo' => $detalle?->motivo ?? 'Demanda comercial',
                        ];
                    })
                    ->schema(fn (ProductoPresentacionDespacho $record): array => [
                        TextInput::make('delta')
                            ->label('Ajuste (+/-)')
                            ->numeric()
                            ->integer()
                            ->required()
                            ->helperText("Múltiplo: {$record->multiplo} un.")
                            // Pedido explícito del usuario: NO un desplegable
                            // con opciones fijas -- un campo libre que el
                            // local tipea normal (4, 13, -7...) y que se
                            // AUTO-CORRIGE en vivo al múltiplo real más
                            // cercano de ESTE producto (15 si escribió 13 y
                            // el múltiplo es 15; -1 múltiplo si puso -7 y el
                            // múltiplo es 5 -> -5, no -10, porque -7 está más
                            // cerca de -5). El servidor vuelve a aplicar el
                            // mismo redondeo como respaldo (ver
                            // guardarSolicitud) por si el valor llega
                            // tamperado.
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, Set $set) use ($record): void {
                                if ($state === null || $state === '') {
                                    return;
                                }
                                $set('delta', $this->redondearAlMultiploMasCercano((int) $state, $record->multiplo));
                            }),
                        Textarea::make('motivo')->label('Motivo')->rows(1)->maxLength(500)->default('Demanda comercial'),
                    ])
                    ->action(function (ProductoPresentacionDespacho $record, array $data): void {
                        $this->guardarSolicitud($record, (int) $data['delta'], $data['motivo'] ?? null);
                    }),
            ])
            ->emptyStateHeading('No hay una Directiva calculada para este local todavía.');
    }

    private function sugerenciaPara(ProductoPresentacionDespacho $producto): ?DirectivaTransferenciaSugerencia
    {
        $calculadoEn = $this->ultimoCalculadoEn();
        if (! $calculadoEn) {
            return null;
        }

        // Una sola consulta para las 15 filas de la tabla en vez de una por
        // producto -- mismo motivo que solicitudActual()/detallePara() de
        // arriba.
        if ($this->sugerenciasCache === null) {
            $this->sugerenciasCache = DirectivaTransferenciaSugerencia::where('local_id', $this->localId)
                ->where('calculado_en', $calculadoEn)
                ->get()
                ->keyBy(fn (DirectivaTransferenciaSugerencia $s): string => "{$s->item_id}|{$s->item_tipo}");
        }

        return $this->sugerenciasCache->get("{$producto->item_id}|{$producto->item_tipo}");
    }

    private function detallePara(ProductoPresentacionDespacho $producto): ?DirectivaAjusteLocalDetalle
    {
        $solicitud = $this->solicitudActual();
        if (! $solicitud) {
            return null;
        }

        // `->detalles` (propiedad, sin paréntesis) reutiliza la colección ya
        // cargada por el `with('detalles')` de solicitudActual() -- llamar
        // `->detalles()->where(...)->first()` (relación como query builder)
        // volvería a consultar la BD en cada llamada, una por columna.
        return $solicitud->detalles
            ->first(fn (DirectivaAjusteLocalDetalle $d): bool => $d->item_id === $producto->item_id && $d->item_tipo === $producto->item_tipo);
    }

    private function guardarSolicitud(ProductoPresentacionDespacho $record, int $deltaUnidades, ?string $motivo): void
    {
        abort_unless(auth()->user()?->hasPermission('directiva-transferencia.ajuste-local.crear'), 403);
        abort_unless($this->localId && $this->localAllowedForUser($this->localId), 403);

        $fecha = $this->fechaDespacho();
        abort_if(! $fecha, 404);

        // El múltiplo real siempre se relee del catálogo -- nunca se confía
        // en el valor que pudiera traer el registro que ya se mostró en
        // pantalla, por si cambió entre que se cargó la tabla y se guardó.
        $productoReal = ProductoPresentacionDespacho::where('item_id', $record->item_id)
            ->where('item_tipo', $record->item_tipo)
            ->where('activo', true)
            ->first();
        abort_unless($productoReal, 404);
        abort_unless($productoReal->multiplo > 0, 422);

        // El campo ya se auto-corrige en vivo en el navegador (ver
        // afterStateUpdated más arriba), pero `delta` sigue siendo una
        // propiedad de formulario tamperable -- se vuelve a redondear acá
        // contra el múltiplo REAL y ACTUAL del producto (nunca el que tenía
        // cuando se abrió el modal), nunca confiando en el valor recibido.
        $deltaUnidades = $this->redondearAlMultiploMasCercano($deltaUnidades, $productoReal->multiplo);
        $multiplos = intdiv($deltaUnidades, $productoReal->multiplo);

        $solicitud = DirectivaAjusteLocalSolicitud::firstOrCreate(
            ['local_id' => $this->localId, 'fecha_despacho' => $fecha],
            [
                'local_nombre' => StockInicialLocal::where('local_id', $this->localId)->value('local_nombre'),
                'creado_por' => auth()->id(),
            ],
        );

        $existente = $solicitud->detalles()
            ->where('item_id', $productoReal->item_id)
            ->where('item_tipo', $productoReal->item_tipo)
            ->first();

        // Un detalle 'aprobado' ya se aplicó a la Directiva -- no se puede
        // editar por acá (el botón ya se oculta, esto es la revalidación
        // real del lado servidor).
        abort_if($existente?->estado === 'aprobado', 403);

        if ($multiplos === 0) {
            if ($existente) {
                $existente->setRelation('solicitud', $solicitud);
                DirectivaAjusteLocalHistorial::registrar($existente, 'retirado');
                $existente->delete();
            }
            Notification::make()->success()->title('Solicitud retirada')->send();

            return;
        }

        $eraNuevo = $existente === null;

        $detalle = DirectivaAjusteLocalDetalle::updateOrCreate(
            ['solicitud_id' => $solicitud->id, 'item_id' => $productoReal->item_id, 'item_tipo' => $productoReal->item_tipo],
            [
                'item_nombre' => $productoReal->item_nombre,
                'multiplo' => $productoReal->multiplo,
                'multiplos_solicitados' => $multiplos,
                'delta_unidades' => $multiplos * $productoReal->multiplo,
                'motivo' => $motivo,
                // Editar una solicitud rechazada la vuelve a poner en
                // revisión -- pedido explícito del usuario ("puede
                // corregirlo hasta yo aprobarlo").
                'estado' => 'pendiente',
                'comentario_admin' => null,
                'revisado_por' => null,
                'revisado_en' => null,
            ],
        );

        // Pedido explícito del usuario: cada sugerido de un local queda
        // como histórico, aunque la fila viva se sobrescriba o se retire
        // después -- ver docblock de la migración/modelo del historial.
        $detalle->setRelation('solicitud', $solicitud);
        DirectivaAjusteLocalHistorial::registrar($detalle, $eraNuevo ? 'creado' : 'editado');

        Notification::make()->success()->title('Solicitud guardada')->body('Queda pendiente de aprobación.')->send();
    }

    /**
     * Redondea al múltiplo MÁS CERCANO (no siempre hacia arriba, a
     * diferencia de ProductoPresentacionDespacho::redondear() que sí
     * redondea siempre hacia arriba para el despacho real) -- ej. con
     * múltiplo=5: 13 -> 15, 12 -> 10, -7 -> -5. Un ajuste en 0 se mantiene
     * en 0 (dispara la rama de "retirar solicitud" en guardarSolicitud()).
     */
    private function redondearAlMultiploMasCercano(int $valor, int $multiplo): int
    {
        if ($multiplo <= 0) {
            return $valor;
        }

        return (int) (round($valor / $multiplo) * $multiplo);
    }
}
