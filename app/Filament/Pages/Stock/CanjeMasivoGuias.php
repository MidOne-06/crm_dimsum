<?php

namespace App\Filament\Pages\Stock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Jobs\ConfirmarCanjeMasivoJob;
use App\Jobs\PrevisualizarCanjeMasivoJob;
use App\Models\CanjeMasivo;
use App\Services\GuiasInternasGatewayClient;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Throwable;

/**
 * "Canjear todo lo filtrado" -- pedido explícito del usuario tras encontrarse
 * con que el canje manual admite máximo 20 guías por operación (límite real
 * de Restaurant, ver MovimientosAlmacenesGatewayClient/API-TI) mientras el
 * backlog real ronda cientos de guías. Dos pasos separados a propósito:
 *
 * 1. Previsualizar (PrevisualizarCanjeMasivoJob): solo LEE el listado (sin
 *    hidratar guía por guía -- ver el docblock del job, esa vuelta cuesta
 *    caro con cientos de guías) y muestra cuántas guías/movimientos/soles
 *    implica.
 * 2. Confirmar (ConfirmarCanjeMasivoJob): recién ahí escribe de verdad,
 *    tanda por tanda, usando los valores que Restaurant ya trae por
 *    defecto para cada grupo -- sin edición humana por movimiento, así que
 *    nunca toca cantidades ni ningún otro campo.
 *
 * Ambos jobs corren en el contenedor `worker` (cola 'movimientos-almacenes'
 * -- el mismo `queue:work` del worker solo escucha nombres de cola fijos,
 * declarados en compose.yaml; usar uno nuevo sin agregarlo ahí lo dejaría
 * encolado para siempre, sin que ningún worker lo procese jamás) -- ver
 * la lección de DespacharSincronizacionesPendientes: un proceso lanzado
 * directo desde el request web no sobrevive en este contenedor.
 */
class CanjeMasivoGuias extends Page implements HasTable
{
    use InteractsWithTable;
    use ScopesLocalsToUser;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-plus';
    protected static ?string $navigationLabel = 'Canje masivo';
    protected static ?string $title = 'Canje masivo de guías internas';
    protected static string|\UnitEnum|null $navigationGroup = 'Guías internas';
    protected static ?int $navigationSort = 12;
    protected static ?string $slug = 'guias-internas/canje-masivo';
    protected string $view = 'filament.pages.stock.canje-masivo-guias';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('movimientos-almacenes.canje-masivo');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('nuevo_canje_masivo')
                ->label('Canjear todo lo filtrado')
                ->icon('heroicon-o-squares-plus')
                ->color('primary')
                ->modalHeading('Canjear todo lo filtrado')
                ->modalDescription('Esto solo genera una vista previa -- no registra ningún movimiento todavía. Vas a poder revisar los totales antes de confirmar de verdad.')
                ->modalWidth('3xl')
                ->modalSubmitActionLabel('Generar vista previa')
                ->schema([
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        DatePicker::make('fecha_inicio')->label('Desde')->native(false)->required()->default(now()->subDays(30)->toDateString()),
                        DatePicker::make('fecha_fin')->label('Hasta')->native(false)->required()->default(now()->toDateString()),
                        Select::make('filtro_por_fecha')->label('Fecha de')->options(['1' => 'Emisión', '0' => 'Traslado'])->native()->default('1'),
                        Select::make('estado')->label('Estado')->options(fn (): array => $this->restaurantEstadoOptions())->native()->default('1'),
                        Select::make('locales')->label('Locales de destino (vacío = todos los permitidos)')->options(fn (): array => $this->restaurantLocalesOptions())->multiple()->searchable()->native(false)->columnSpanFull(),
                        Select::make('motivo')->label('Motivo')->options(fn (): array => $this->restaurantMotivoOptions())->native()->placeholder('Todos')->columnSpanFull(),
                    ]),
                ])
                ->action(function (array $data): void {
                    $this->crearYPrevisualizarCanjeMasivo($data);
                }),
            Action::make('actualizar')
                ->label('Actualizar')
                ->icon('heroicon-o-arrow-path')
                ->action(fn () => $this->resetTable()),
        ];
    }

    /** @param array<string, mixed> $data */
    private function crearYPrevisualizarCanjeMasivo(array $data): void
    {
        abort_unless(auth()->user()?->hasPermission('movimientos-almacenes.canje-masivo'), 403);

        // OJO, dos motivos reales para nunca mandar 'locales' vacío acá,
        // comprobados en vivo:
        // 1. restrictLocalIdsToUser([]) devuelve [] -- filtra una lista
        //    vacía, no la reemplaza por "todos los permitidos". Un usuario
        //    restringido que deja el filtro en blanco pensando "todos los
        //    míos" mandaría 'locales' => '' -- fuga de alcance real.
        // 2. Con buscar_segun=2 (por local de destino, el criterio correcto
        //    acá porque el canje es sobre RECEPCIÓN), Restaurant no trata
        //    'locales' vacío como "todos" -- lo limita al local de la propia
        //    sesión del gateway y devuelve total=0 (comprobado en vivo:
        //    0 resultados con locales vacío, 137+ con la lista completa).
        // Por eso, sin selección explícita, se completa SIEMPRE con la
        // lista completa de locales permitidos para este usuario (no con
        // un array vacío) antes de restringir por permisos.
        $localesSeleccionados = (array) ($data['locales'] ?? []);
        if ($localesSeleccionados === []) {
            $localesSeleccionados = array_keys($this->restaurantLocalesOptions());
        }
        $locales = $this->restrictLocalIdsToUser($localesSeleccionados);

        $desde = (string) ($data['fecha_inicio'] ?? now()->subDays(30)->toDateString());
        $hasta = (string) ($data['fecha_fin'] ?? now()->toDateString());
        if ($hasta < $desde) {
            [$desde, $hasta] = [$hasta, $desde];
        }

        $filtros = [
            'fecha_inicio' => $desde,
            'fecha_fin' => $hasta,
            'filtro_por_fecha' => (string) ($data['filtro_por_fecha'] ?? '1'),
            'estado' => (string) ($data['estado'] ?? '1'),
            'motivo' => (string) ($data['motivo'] ?? '-1'),
            'locales' => implode(',', $locales),
            'buscar_segun' => '2', // por local de destino -- es el criterio relevante para recepción
            'almacen' => '-1',
            'serie' => '', 'numero' => '', 'codigo' => '', 'item_ids' => '', 'item_tipos' => '',
        ];

        $canje = CanjeMasivo::create(['estado' => 'previsualizando', 'filtros' => $filtros, 'iniciado_por' => auth()->id()]);
        PrevisualizarCanjeMasivoJob::dispatch($canje->id);

        Notification::make()->title('Vista previa en camino')->body('Puede tardar unos minutos según cuántas guías coincidan con el filtro. Esta tabla se actualiza sola.')->success()->send();
        $this->resetTable();
    }

    public function confirmarCanjeMasivo(int $id): void
    {
        abort_unless(auth()->user()?->hasPermission('movimientos-almacenes.canje-masivo'), 403);

        $canje = CanjeMasivo::find($id);
        if (! $canje || ! $canje->estaListoParaConfirmar()) {
            Notification::make()->danger()->title('Ya no se puede confirmar')->body('Esta vista previa ya no está lista para confirmar -- puede haberse confirmado o fallado mientras tanto.')->send();

            return;
        }

        $canje->update(['estado' => 'confirmando', 'confirmado_en' => now()]);
        ConfirmarCanjeMasivoJob::dispatch($canje->id);

        Notification::make()->title('Confirmación en camino')->body("Registrando movimientos reales para {$canje->total_guias_procesables} guías, en tandas de 20. Esta tabla se actualiza sola.")->warning()->send();
        $this->resetTable();
    }

    public function cancelarPendiente(int $id): void
    {
        $canje = CanjeMasivo::find($id);
        if ($canje && $canje->estado === 'previsualizando') {
            $canje->update(['estado' => 'fallido', 'mensaje_error' => 'Cancelado manualmente por '.(auth()->user()?->name ?? 'admin').'.']);
        }
    }

    /**
     * Detiene una confirmación real ya en curso, entre tandas -- antes esto
     * requería matar el worker a mano por SSH (pasó de verdad el
     * 2026-09-08, con una corrida real de 611 guías). ConfirmarCanjeMasivoJob
     * revisa el estado ANTES de lanzar cada tanda de 20 y corta solo ahí, así
     * que la tanda que ya esté en vuelo en ese momento sigue su curso hasta
     * el final (no se puede cortar a la fuerza sin generar el mismo hueco de
     * contabilidad que motivó este botón) -- lo ya confirmado nunca se
     * reversa.
     */
    public function detenerConfirmacion(int $id): void
    {
        abort_unless(auth()->user()?->hasPermission('movimientos-almacenes.canje-masivo'), 403);

        $canje = CanjeMasivo::find($id);
        if ($canje && $canje->estado === 'confirmando') {
            $canje->update([
                'estado' => 'cancelado',
                'mensaje_error' => 'Detenido manualmente por '.(auth()->user()?->name ?? 'admin').' mientras estaba en curso. Lo ya confirmado hasta ese punto queda tal cual, sin reversar nada.',
            ]);
            Notification::make()->title('Deteniendo la corrida')->body('La tanda que esté en vuelo en este momento va a terminar de procesarse; no se van a lanzar tandas nuevas después de esa.')->warning()->send();
        }
    }

    /** @return array<string, string> */
    private function restaurantLocalesOptions(): array
    {
        try {
            return collect($this->scopeLocalsToUser(app(GuiasInternasGatewayClient::class)->locales()))
                ->mapWithKeys(fn (array $local): array => [(string) ($local['id'] ?? '') => (string) ($local['name'] ?? '')])
                ->filter(fn (string $name, string $id): bool => $id !== '' && $name !== '')
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string, string> */
    private function restaurantMotivoOptions(): array
    {
        try {
            return collect(app(GuiasInternasGatewayClient::class)->motivos())
                ->mapWithKeys(fn (array $motivo): array => [(string) ($motivo['id'] ?? '') => (string) ($motivo['name'] ?? '')])
                ->filter(fn (string $name, string $id): bool => $id !== '' && $name !== '')
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string, string> */
    private function restaurantEstadoOptions(): array
    {
        try {
            return collect(app(GuiasInternasGatewayClient::class)->estados())
                ->mapWithKeys(fn (array $estado): array => [(string) ($estado['id'] ?? '') => (string) ($estado['name'] ?? '')])
                ->filter(fn (string $name, string $id): bool => $id !== '' && $name !== '')
                ->all();
        } catch (Throwable) {
            return ['1' => 'Activa', '0' => 'Anulada'];
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(CanjeMasivo::query()->latest('id'))
            ->poll('5s')
            ->columns([
                TextColumn::make('id')->label('Cód.'),
                TextColumn::make('estado')->label('Estado')->badge()->formatStateUsing(fn ($state): string => match ($state) {
                    'previsualizando' => 'Previsualizando…',
                    'listo' => 'Listo para confirmar',
                    'confirmando' => 'Confirmando…',
                    'completado' => 'Completado',
                    'completado_con_errores' => 'Completado con errores',
                    'fallido' => 'Fallido',
                    'cancelado' => 'Detenido manualmente',
                    default => ucfirst((string) $state),
                })->color(fn ($state): string => match ($state) {
                    'listo' => 'warning',
                    'completado' => 'success',
                    'completado_con_errores' => 'danger',
                    'fallido' => 'danger',
                    'cancelado' => 'gray',
                    default => 'gray',
                }),
                TextColumn::make('filtros')->label('Filtro')->state(fn (CanjeMasivo $r): string => ($r->filtros['fecha_inicio'] ?? '').' al '.($r->filtros['fecha_fin'] ?? ''))->wrap(),
                TextColumn::make('total_guias_procesables')->label('Guías')->numeric()->alignEnd(),
                TextColumn::make('total_guias_excluidas')->label('Excluidas')->numeric()->alignEnd()->toggleable(),
                TextColumn::make('total_grupos_estimados')->label('Movimientos (est.)')->numeric()->alignEnd(),
                TextColumn::make('total_valorizado_estimado')->label('Valorizado (est.)')->numeric(2)->alignEnd(),
                TextColumn::make('total_guias_confirmadas')->label('Confirmadas')->numeric()->alignEnd()->color('success'),
                TextColumn::make('total_guias_fallidas')->label('Fallidas')->numeric()->alignEnd()->color(fn ($state): string => (int) $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('iniciadoPor.name')->label('Por')->toggleable(),
                TextColumn::make('created_at')->label('Creado')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->recordActions([
                Action::make('confirmar')
                    ->label('Confirmar canje real')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('danger')
                    ->visible(fn (CanjeMasivo $r): bool => $r->estaListoParaConfirmar())
                    ->requiresConfirmation()
                    ->modalHeading('¿Confirmar el canje masivo de verdad?')
                    ->modalDescription(fn (CanjeMasivo $r): string => "Esto va a registrar en Restaurant {$r->total_grupos_estimados} movimiento(s) reales, marcando {$r->total_guias_procesables} guía(s) como recepcionadas, por un valorizado estimado de {$r->total_valorizado_estimado}. No se puede deshacer desde acá.")
                    ->modalSubmitActionLabel('Sí, registrar los movimientos reales')
                    ->schema([
                        Checkbox::make('confirmo')->label('Entiendo que esto registra movimientos reales e irreversibles en Restaurant.')->required()->rule('accepted'),
                    ])
                    ->action(fn (CanjeMasivo $r) => $this->confirmarCanjeMasivo($r->id)),
                Action::make('cancelar')
                    ->label('Cancelar')
                    ->icon('heroicon-o-x-mark')
                    ->color('gray')
                    ->visible(fn (CanjeMasivo $r): bool => $r->estado === 'previsualizando')
                    ->requiresConfirmation()
                    ->action(fn (CanjeMasivo $r) => $this->cancelarPendiente($r->id)),
                Action::make('detener')
                    ->label('Detener')
                    ->icon('heroicon-o-stop-circle')
                    ->color('danger')
                    ->visible(fn (CanjeMasivo $r): bool => $r->estado === 'confirmando')
                    ->requiresConfirmation()
                    ->modalHeading('¿Detener esta confirmación en curso?')
                    ->modalDescription('Lo que ya se confirmó hasta ahora queda tal cual -- no se reversa nada. Solo se evita que se sigan registrando movimientos nuevos a partir de la próxima tanda.')
                    ->modalSubmitActionLabel('Sí, detener')
                    ->action(fn (CanjeMasivo $r) => $this->detenerConfirmacion($r->id)),
                Action::make('ver_detalle')
                    ->label('Ver detalle')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (CanjeMasivo $r): string => 'Canje masivo #'.$r->id)
                    ->modalWidth('4xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar')
                    ->modalContent(fn (CanjeMasivo $r) => view('filament.pages.stock.partials.canje-masivo-detalle', ['canje' => $r])),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('Todavía no se corrió ningún canje masivo.');
    }
}
