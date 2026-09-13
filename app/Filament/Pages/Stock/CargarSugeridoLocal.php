<?php

namespace App\Filament\Pages\Stock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Models\DirectivaAjusteLocalDetalle;
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
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Cargar mi sugerido" -- pedido explícito del usuario (2026-09-13): cada
 * local puede pedir un ajuste sobre la cantidad que la Directiva de
 * Transferencia ya calculó para él, pero NUNCA un número libre -- siempre
 * en MÚLTIPLOS del despacho de ese producto (ver ProductoPresentacionDespacho,
 * mismo múltiplo que usa el propio cálculo para redondear). El
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
        if (! $this->localId || ! $this->localAllowedForUser($this->localId)) {
            return null;
        }

        return DirectivaTransferenciaSugerencia::where('local_id', $this->localId)->max('calculado_en');
    }

    public function fechaDespacho(): ?string
    {
        $calculadoEn = $this->ultimoCalculadoEn();
        if (! $calculadoEn) {
            return null;
        }

        $fecha = DirectivaTransferenciaSugerencia::where('local_id', $this->localId)
            ->where('calculado_en', $calculadoEn)
            ->value('fecha_despacho');

        return $fecha ? (string) $fecha : null;
    }

    private function solicitudActual(): ?DirectivaAjusteLocalSolicitud
    {
        $fecha = $this->fechaDespacho();
        if (! $fecha || ! $this->localId || ! $this->localAllowedForUser($this->localId)) {
            return null;
        }

        return DirectivaAjusteLocalSolicitud::where('local_id', $this->localId)
            ->where('fecha_despacho', $fecha)
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
                    ->modalDescription(fn (ProductoPresentacionDespacho $record): string => "Múltiplo de despacho: {$record->multiplo} unidades. Indica cuántos múltiplos de más (positivo) o de menos (negativo) necesitas.")
                    ->fillForm(function (ProductoPresentacionDespacho $record): array {
                        $detalle = $this->detallePara($record);

                        return [
                            'multiplos' => $detalle?->multiplos_solicitados,
                            'motivo' => $detalle?->motivo,
                        ];
                    })
                    ->schema([
                        TextInput::make('multiplos')
                            ->label('Múltiplos de ajuste (+/-)')
                            ->numeric()
                            ->integer()
                            ->required()
                            ->helperText('Ej. 2 = pide 2 múltiplos más; -1 = pide 1 múltiplo menos. Nunca una cantidad suelta.'),
                        Textarea::make('motivo')->label('Motivo')->rows(2)->maxLength(500),
                    ])
                    ->action(function (ProductoPresentacionDespacho $record, array $data): void {
                        $this->guardarSolicitud($record, (int) $data['multiplos'], $data['motivo'] ?? null);
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

        return DirectivaTransferenciaSugerencia::where('local_id', $this->localId)
            ->where('calculado_en', $calculadoEn)
            ->where('item_id', $producto->item_id)
            ->where('item_tipo', $producto->item_tipo)
            ->first();
    }

    private function detallePara(ProductoPresentacionDespacho $producto): ?DirectivaAjusteLocalDetalle
    {
        $solicitud = $this->solicitudActual();
        if (! $solicitud) {
            return null;
        }

        return $solicitud->detalles()
            ->where('item_id', $producto->item_id)
            ->where('item_tipo', $producto->item_tipo)
            ->first();
    }

    private function guardarSolicitud(ProductoPresentacionDespacho $record, int $multiplos, ?string $motivo): void
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
            $existente?->delete();
            Notification::make()->success()->title('Solicitud retirada')->send();

            return;
        }

        DirectivaAjusteLocalDetalle::updateOrCreate(
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

        Notification::make()->success()->title('Solicitud guardada')->body('Queda pendiente de aprobación.')->send();
    }
}
