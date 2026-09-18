<?php

namespace App\Filament\Pages\Produccion;

use App\Models\ProduccionDiariaAuditoria;
use App\Models\ProduccionDiariaCierre;
use App\Models\ProduccionDiariaDetalle;
use App\Models\ProduccionDiariaSalida;
use App\Models\ProduccionDiariaTanda;
use App\Models\ProduccionProducto;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn as InfolistTableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Módulo autónomo: catálogo, tandas y cierres propios de Producción. */
class RegistroProduccionDiaria extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static ?string $navigationLabel = 'Registro de producción';
    protected static ?string $title = 'Producción de hoy';
    protected static string|\UnitEnum|null $navigationGroup = 'Producción';
    protected static ?int $navigationSort = 1;
    protected static ?string $slug = 'produccion/registro';
    protected string $view = 'filament.pages.produccion.registro-produccion-diaria';

    /** @var array<string, mixed> */
    public array $data = [];
    /** @var array<int, array<string, mixed>> */
    public array $resumenProduccion = [];
    /** @var array<int, array<string, mixed>> */
    public array $tandasRecientes = [];
    /** @var array<int, array<string, mixed>> */
    public array $salidasRecientes = [];
    /** @var array<int, array<string, mixed>> */
    public array $productosParaRegistro = [];
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $productosPorCategoria = [];
    public ?int $cierreId = null;
    public string $estado = 'nuevo';
    public ?string $diaAnteriorFecha = null;
    public bool $diaAnteriorSinCerrar = false;
    public bool $diaAnteriorSinAprobar = false;
    private string $intentoGuardar = 'borrador';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->hasPermission('produccion-diaria.view') || $user?->hasPermission('produccion-diaria.registrar')
            || $user?->hasPermission('produccion-diaria.registrar-tanda') || $user?->hasPermission('produccion-diaria.aprobar'));
    }

    public function mount(): void { $this->cargarHoy(); }

    public function form(Schema $schema): Schema
    {
        return $schema->components($this->cierreSchema())->statePath('data');
    }

    /** @return array<int, mixed> */
    private function cierreSchema(): array
    {
        return [
            Grid::make(1)->columnSpanFull()->schema([
                Textarea::make('observacion')->label('Observación general')->rows(2)->maxLength(1000)->columnSpanFull()->disabled(fn (): bool => $this->soloLectura()),
                Repeater::make('items')->label('')->hiddenLabel()->addable(false)->deletable(false)->reorderable(false)->itemNumbers(false)->compact()->columnSpanFull()
                    ->table([
                        TableColumn::make('Producto')->width('18rem'),
                        TableColumn::make('Unidad')->width('6.5rem'),
                        TableColumn::make('Inicial')->width('7rem'),
                        TableColumn::make('Producido')->width('7rem'),
                        TableColumn::make('Salidas')->width('7rem'),
                        TableColumn::make('Esperado')->width('7rem'),
                        TableColumn::make('Final físico')->width('8rem'),
                        TableColumn::make('Diferencia')->width('7rem'),
                        TableColumn::make('Motivo')->width('15rem'),
                    ])
                    ->schema([
                        Hidden::make('producto_id')->dehydrated(),
                        Hidden::make('origen_inicial')->dehydrated(),
                        Hidden::make('item_codigo')->dehydrated(),
                        TextInput::make('item_nombre')->hiddenLabel()->readOnly()->dehydrated(),
                        TextInput::make('unidad')->hiddenLabel()->readOnly()->dehydrated(),
                        TextInput::make('stock_inicial')->hiddenLabel()->numeric()->minValue(0)->required()->live()->readOnly(fn (Get $get): bool => $get('origen_inicial') === 'cierre_anterior')
                            ->disabled(fn (): bool => $this->soloLectura())->afterStateUpdated(fn (Get $get, Set $set) => $this->actualizarCalculos($get, $set)),
                        TextInput::make('producido_hoy')->hiddenLabel()->numeric()->readOnly()->dehydrated(),
                        TextInput::make('salidas_hoy')->hiddenLabel()->numeric()->readOnly()->dehydrated(),
                        TextInput::make('stock_esperado')->hiddenLabel()->numeric()->readOnly()->dehydrated(),
                        TextInput::make('stock_final')->hiddenLabel()->numeric()->minValue(0)->inputMode('decimal')->live()->required(fn (): bool => $this->intentoGuardar === 'enviado')
                            ->disabled(fn (): bool => $this->soloLectura())->afterStateUpdated(fn (Get $get, Set $set) => $this->actualizarCalculos($get, $set)),
                        TextInput::make('diferencia')->hiddenLabel()->numeric()->readOnly()->dehydrated(),
                        TextInput::make('observacion')->hiddenLabel()->maxLength(500)
                            ->required(fn (Get $get): bool => $this->intentoGuardar === 'enviado' && abs((float) ($get('diferencia') ?? 0)) > 0.0001)
                            ->disabled(fn (): bool => $this->soloLectura()),
                    ]),
            ]),
        ];
    }

    /**
     * "Producción acumulada de hoy" en modal -- pedido explícito del
     * usuario (2026-09-17): antes era una sección siempre visible en la
     * página; se convierte en Action con Infolist dentro del modal (mismo
     * estándar nativo de Filament que ya usa "Ver" en Salidas de Stock,
     * ver SalidasStock.php) en vez de un modal armado a mano.
     */
    public function produccionAcumuladaAction(): Action
    {
        return Action::make('produccionAcumulada')
            ->label('Producción acumulada de hoy')
            ->icon('heroicon-o-chart-bar')
            ->color('gray')
            ->modalHeading('Producción acumulada de hoy')
            ->modalWidth('4xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Cerrar')
            ->schema([
                RepeatableEntry::make('resumen')->label('')->state(fn (): array => $this->resumenProduccion)
                    ->table([
                        InfolistTableColumn::make('Producto'),
                        InfolistTableColumn::make('Código'),
                        InfolistTableColumn::make('Bashes')->alignEnd(),
                        InfolistTableColumn::make('Cantidad')->alignEnd(),
                        InfolistTableColumn::make('Unidad'),
                    ])
                    ->schema([
                        TextEntry::make('nombre')->label('')->weight('medium'),
                        TextEntry::make('codigo')->label('')->placeholder('—'),
                        TextEntry::make('tandas')->label(''),
                        TextEntry::make('cantidad')->label('')->numeric(2),
                        TextEntry::make('unidad')->label(''),
                    ])
                    ->contained(false),
            ]);
    }

    public function registrarTandaProductoAction(): Action
    {
        return Action::make('registrarTandaProducto')
            ->label('Registrar bash')
            ->icon('heroicon-o-plus')
            ->visible(fn (): bool => $this->puedeRegistrarTanda() && ! $this->soloLectura())
            ->modalHeading(fn (Action $action): string => 'Registrar bash · '.$this->productoDeAccion($action)->nombre)
            ->modalWidth('5xl')
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitActionLabel('Registrar bash')
            ->modalCancelActionLabel('Cancelar')
            ->fillForm(function (Action $action): array {
                $producto = $this->productoDeAccion($action);
                $resumen = $this->resumenProducto($producto->id);

                return [
                    'producto_id' => $producto->id,
                    'producto' => trim(($producto->codigo ? $producto->codigo.' · ' : '').$producto->nombre),
                    'stock_inicial' => $this->formatearCantidad($resumen['stock_inicial']),
                    'producido_hoy' => $this->formatearCantidad($resumen['producido_hoy']),
                    'disponible' => $this->formatearCantidad($resumen['disponible']),
                    'cantidad' => null,
                    'nota' => null,
                ];
            })
            ->schema([
                Grid::make(['default' => 1, 'md' => 4])->columnSpanFull()->schema([
                    Hidden::make('producto_id')->required(),
                    TextInput::make('producto')->label('Producto')->readOnly()->dehydrated(false)->columnSpanFull(),
                    TextInput::make('stock_inicial')->label('Stock inicial')->readOnly()->dehydrated(false),
                    TextInput::make('producido_hoy')->label('Acumulado producido')->readOnly()->dehydrated(false),
                    TextInput::make('disponible')->label('Disponible')->readOnly()->dehydrated(false),
                    TextInput::make('cantidad')->label('Cantidad producida')->numeric()->inputMode('decimal')->minValue(0.0001)->required(),
                    Textarea::make('nota')->label('Nota')->rows(2)->maxLength(500)->columnSpanFull(),
                ]),
            ])
            ->action(function (array $data): void {
                abort_unless($this->puedeRegistrarTanda(), 403);
                $producto = ProduccionProducto::query()->where('activo', true)->find($data['producto_id'] ?? null);
                $cantidad = is_numeric($data['cantidad'] ?? null) ? (float) $data['cantidad'] : 0;
                $nota = trim((string) ($data['nota'] ?? '')) ?: null;
                if (! $producto) throw ValidationException::withMessages(['producto_id' => 'El producto no está disponible para registrar.']);
                if ($cantidad <= 0) throw ValidationException::withMessages(['cantidad' => 'Ingresa una cantidad mayor que cero.']);

                $this->guardarTanda($producto, $cantidad, $nota);
                Notification::make()->success()->title('Bash registrado')->body(number_format($cantidad, 2).' '.$producto->unidad.' de '.$producto->nombre)->send();
                $this->cargarHoy();
            });
    }

    /**
     * Salida nativa de Producción -- pedido explícito del usuario (2026-09-17):
     * la salida real de Fábrica hacia despacho existe en Restaurant (Guías
     * Internas), pero ese módulo no tiene por qué depender de Restaurant
     * para llevar su propio conteo. Mismo patrón que "Registrar tanda"
     * (misma tabla de cierre, mismo candado por fecha), pero resta en vez
     * de sumar -- valida contra "disponible" (inicial + producido - ya
     * salido), nunca deja que la salida deje el disponible en negativo.
     */
    public function registrarSalidaProductoAction(): Action
    {
        return Action::make('registrarSalidaProducto')
            ->label('Registrar salida')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
            ->visible(fn (): bool => $this->puedeRegistrar() && ! $this->soloLectura())
            ->modalHeading(fn (Action $action): string => 'Registrar salida · '.$this->productoDeAccion($action)->nombre)
            ->modalWidth('5xl')
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitActionLabel('Registrar salida')
            ->modalCancelActionLabel('Cancelar')
            ->fillForm(function (Action $action): array {
                $producto = $this->productoDeAccion($action);
                $resumen = $this->resumenProducto($producto->id);

                return [
                    'producto_id' => $producto->id,
                    'producto' => trim(($producto->codigo ? $producto->codigo.' · ' : '').$producto->nombre),
                    'disponible' => $this->formatearCantidad($resumen['disponible']),
                    'cantidad' => null,
                    'destino' => 'despacho',
                    'nota' => null,
                ];
            })
            ->schema([
                Grid::make(['default' => 1, 'md' => 4])->columnSpanFull()->schema([
                    Hidden::make('producto_id')->required(),
                    TextInput::make('producto')->label('Producto')->readOnly()->dehydrated(false)->columnSpanFull(),
                    TextInput::make('disponible')->label('Disponible ahora')->readOnly()->dehydrated(false)->columnSpan(['md' => 2]),
                    TextInput::make('cantidad')->label('Cantidad de salida')->numeric()->inputMode('decimal')->minValue(0.0001)->required()->columnSpan(['md' => 2]),
                    Select::make('destino')->label('Destino')->native(false)->required()->default('despacho')->options([
                        'despacho' => 'Área de despacho',
                        'merma' => 'Merma / descarte',
                        'ajuste' => 'Ajuste de conteo',
                        'otro' => 'Otro',
                    ])->columnSpan(['md' => 2]),
                    Textarea::make('nota')->label('Nota')->rows(2)->maxLength(500)->columnSpanFull(),
                ]),
            ])
            ->action(function (array $data): void {
                abort_unless($this->puedeRegistrar(), 403);
                $producto = ProduccionProducto::query()->where('activo', true)->find($data['producto_id'] ?? null);
                $cantidad = is_numeric($data['cantidad'] ?? null) ? (float) $data['cantidad'] : 0;
                $destino = (string) ($data['destino'] ?? 'despacho');
                $nota = trim((string) ($data['nota'] ?? '')) ?: null;
                if (! $producto) throw ValidationException::withMessages(['producto_id' => 'El producto no está disponible para registrar.']);
                if ($cantidad <= 0) throw ValidationException::withMessages(['cantidad' => 'Ingresa una cantidad mayor que cero.']);

                $this->guardarSalida($producto, $cantidad, $destino, $nota);
                Notification::make()->success()->title('Salida registrada')->body(number_format($cantidad, 2).' '.$producto->unidad.' de '.$producto->nombre)->send();
                $this->cargarHoy();
            });
    }

    private function guardarSalida(ProduccionProducto $producto, float $cantidad, string $destino, ?string $nota): void
    {
        // Defensa en profundidad -- barrida de permisos (2026-09-17): la
        // Action ya oculta el botón y valida antes de llamar acá, pero este
        // método privado no debe confiar en que SIEMPRE se invoque desde
        // ahí. Mismo patrón que guardar()/aprobar() en esta misma clase.
        abort_unless($this->puedeRegistrar(), 403);
        DB::transaction(function () use ($producto, $cantidad, $destino, $nota): void {
            $cierre = ProduccionDiariaCierre::query()->whereDate('fecha', $this->fechaOperativa())->lockForUpdate()->first();
            if ($cierre?->estado === 'aprobado') throw ValidationException::withMessages(['data.salida.producto_id' => 'El cierre de hoy ya está aprobado.']);
            if ($cierre?->estado === 'enviado') throw ValidationException::withMessages(['data.salida.producto_id' => 'El cierre está enviado; no se pueden agregar salidas.']);

            $fecha = $this->fechaOperativa();
            $inicial = $this->stockInicialAnterior($fecha, $producto->id) ?? 0.0;
            $producido = $this->totalProducido($fecha, $producto->id);
            $salidoYa = $this->totalSalidas($fecha, $producto->id);
            $disponible = round($inicial + $producido - $salidoYa, 4);
            if ($cantidad > $disponible + 0.0001) {
                throw ValidationException::withMessages(['data.salida.cantidad' => "No hay suficiente disponible: quedan {$this->formatearCantidad($disponible)} {$producto->unidad}."]);
            }

            $cierre ??= new ProduccionDiariaCierre(['fecha' => $fecha, 'area' => 'FABRICA', 'estado' => 'borrador', 'creado_por' => auth()->id()]);
            $cierre->save();
            $salidaCreada = $cierre->salidas()->create([
                'producto_id' => $producto->id, 'item_id' => (string) $producto->id, 'item_tipo' => 'produccion', 'item_codigo' => $producto->codigo,
                'item_nombre' => $producto->nombre, 'unidad' => $producto->unidad, 'cantidad' => $cantidad, 'destino' => $destino, 'nota' => $nota, 'registrado_por' => auth()->id(),
            ]);
            $this->auditar($cierre, 'salida_registrada', null, ['salida' => ['id' => $salidaCreada->id, 'producto_id' => $producto->id, 'cantidad' => $cantidad, 'destino' => $destino, 'nota' => $nota]]);
        });
    }

    private function guardarTanda(ProduccionProducto $producto, float $cantidad, ?string $nota): void
    {
        // Defensa en profundidad -- ver comentario equivalente en guardarSalida().
        abort_unless($this->puedeRegistrarTanda(), 403);
        DB::transaction(function () use ($producto, $cantidad, $nota): void {
            $cierre = ProduccionDiariaCierre::query()->whereDate('fecha', $this->fechaOperativa())->lockForUpdate()->first();
            if ($cierre?->estado === 'aprobado') throw ValidationException::withMessages(['data.tanda.producto_id' => 'El cierre de hoy ya está aprobado.']);
            if ($cierre?->estado === 'enviado') throw ValidationException::withMessages(['data.tanda.producto_id' => 'El cierre está enviado; no se pueden agregar bashes.']);
            $cierre ??= new ProduccionDiariaCierre(['fecha' => $this->fechaOperativa(), 'area' => 'FABRICA', 'estado' => 'borrador', 'creado_por' => auth()->id()]);
            $cierre->save();
            $tandaCreada = $cierre->tandas()->create([
                'producto_id' => $producto->id, 'item_id' => (string) $producto->id, 'item_tipo' => 'produccion', 'item_codigo' => $producto->codigo,
                'item_nombre' => $producto->nombre, 'unidad' => $producto->unidad, 'cantidad' => $cantidad, 'nota' => $nota, 'registrado_por' => auth()->id(),
            ]);
            $this->auditar($cierre, 'tanda_registrada', null, ['tanda' => ['id' => $tandaCreada->id, 'producto_id' => $producto->id, 'cantidad' => $cantidad, 'nota' => $nota]]);
        });
    }

    public function registrarCierreFisicoAction(): Action
    {
        return Action::make('registrarCierreFisico')
            ->label('Registrar cierre físico')
            ->icon('heroicon-o-clipboard-document-check')
            ->visible(fn (): bool => $this->puedeRegistrar() && ! $this->soloLectura())
            ->modalHeading('Conciliación de cierre')
            ->modalWidth('7xl')
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitActionLabel('Enviar cierre')
            ->modalCancelActionLabel('Cancelar')
            ->extraModalFooterActions(fn (Action $action): array => [
                $action->makeModalSubmitAction('guardarBorrador', ['destino' => 'borrador'])->label('Guardar borrador')->color('gray'),
            ])
            ->fillForm(function (): array {
                $this->cargarHoy();

                return $this->data;
            })
            ->schema($this->cierreSchema())
            ->beforeFormValidated(function (Action $action): void {
                $this->intentoGuardar = ($action->getArguments()['destino'] ?? 'enviado') === 'borrador' ? 'borrador' : 'enviado';
            })
            ->action(function (array $data, Action $action): void {
                $this->guardar((string) ($action->getArguments()['destino'] ?? 'enviado'), $data);
            });
    }

    public function aprobar(): void
    {
        abort_unless((bool) auth()->user()?->hasPermission('produccion-diaria.aprobar'), 403);
        DB::transaction(function (): void {
            $cierre = ProduccionDiariaCierre::query()->lockForUpdate()->with('detalles')->findOrFail($this->cierreId);
            if ($cierre->estado !== 'enviado') throw ValidationException::withMessages(['items' => 'Solo se puede aprobar un cierre enviado.']);
            foreach ($cierre->detalles as $detalle) {
                if ($detalle->stock_final === null) throw ValidationException::withMessages(['items' => 'Todos los productos requieren stock final físico.']);
                if (abs((float) $detalle->diferencia) > 0.0001 && blank($detalle->observacion)) throw ValidationException::withMessages(['items' => "{$detalle->item_nombre}: indica el motivo de la diferencia."]);
            }
            $antes = $this->snapshot($cierre);
            $cierre->update(['estado' => 'aprobado', 'aprobado_por' => auth()->id(), 'aprobado_en' => now()]);
            $this->auditar($cierre, 'aprobado', $antes, $this->snapshot($cierre->fresh('detalles')));
        });
        Notification::make()->success()->title('Cierre aprobado')->body('El stock final aprobado alimentará el próximo cierre.')->send();
        $this->cargarHoy();
    }

    /**
     * Corregir una tanda/salida ya registrada -- barrida de huecos
     * funcionales (2026-09-17): antes, un error de tipeo (cantidad o nota)
     * no se podía corregir, solo se podía seguir sumando registros nuevos
     * encima. Mismo candado de estado que registrar: solo mientras el
     * cierre del día siga en borrador (sin enviar), nunca después.
     */
    /**
     * Corregir una tanda ya registrada -- barrida de huecos funcionales
     * (2026-09-17): antes, un error de tipeo (cantidad o nota) no se podía
     * corregir, solo se podía seguir sumando registros nuevos encima.
     * Mismo candado de estado que registrar: solo mientras el cierre del
     * día siga en 'borrador' (sin enviar), nunca después.
     */
    public function editarTandaAction(): Action
    {
        return Action::make('editarTanda')
            ->label('Editar')->icon('heroicon-o-pencil-square')->color('gray')->size('sm')
            ->visible(fn (): bool => $this->puedeRegistrarTanda() && $this->estado === 'borrador')
            ->modalHeading('Editar bash')
            ->modalWidth('lg')
            ->fillForm(function (Action $action): array {
                $tanda = ProduccionDiariaTanda::findOrFail((int) ($action->getArguments()['tandaId'] ?? 0));

                return ['tanda_id' => $tanda->id, 'cantidad' => (float) $tanda->cantidad, 'nota' => $tanda->nota];
            })
            ->schema([
                Hidden::make('tanda_id')->required(),
                TextInput::make('cantidad')->label('Cantidad producida')->numeric()->inputMode('decimal')->minValue(0.0001)->required(),
                Textarea::make('nota')->label('Nota')->rows(2)->maxLength(500),
            ])
            ->action(function (array $data): void {
                abort_unless($this->puedeRegistrarTanda(), 403);
                $cantidad = is_numeric($data['cantidad'] ?? null) ? (float) $data['cantidad'] : 0;
                if ($cantidad <= 0) throw ValidationException::withMessages(['cantidad' => 'Ingresa una cantidad mayor que cero.']);
                DB::transaction(function () use ($data, $cantidad): void {
                    $tanda = ProduccionDiariaTanda::query()->whereHas('cierre', fn ($q) => $q->where('id', $this->cierreId)->where('estado', 'borrador'))
                        ->lockForUpdate()->findOrFail($data['tanda_id']);
                    $antes = $tanda->only(['cantidad', 'nota']);
                    $nota = trim((string) ($data['nota'] ?? '')) ?: null;
                    $tanda->update(['cantidad' => $cantidad, 'nota' => $nota]);
                    $this->auditar($tanda->cierre, 'tanda_editada', ['tanda' => ['id' => $tanda->id, ...$antes]], ['tanda' => ['id' => $tanda->id, 'cantidad' => $cantidad, 'nota' => $nota]]);
                });
                Notification::make()->success()->title('Bash actualizado')->send();
                $this->cargarHoy();
            });
    }

    public function eliminarTandaAction(): Action
    {
        return Action::make('eliminarTanda')
            ->label('Eliminar')->icon('heroicon-o-trash')->color('danger')->size('sm')
            ->visible(fn (): bool => $this->puedeRegistrarTanda() && $this->estado === 'borrador')
            ->requiresConfirmation()
            ->modalHeading('¿Eliminar este bash?')
            ->action(function (Action $action): void {
                abort_unless($this->puedeRegistrarTanda(), 403);
                $tandaId = (int) ($action->getArguments()['tandaId'] ?? 0);
                DB::transaction(function () use ($tandaId): void {
                    $tanda = ProduccionDiariaTanda::query()->whereHas('cierre', fn ($q) => $q->where('id', $this->cierreId)->where('estado', 'borrador'))
                        ->lockForUpdate()->findOrFail($tandaId);
                    $cierre = $tanda->cierre;
                    $antes = ['tanda' => $tanda->only(['id', 'producto_id', 'item_nombre', 'cantidad', 'nota'])];
                    $tanda->delete();
                    $this->auditar($cierre, 'tanda_eliminada', $antes, []);
                });
                Notification::make()->success()->title('Bash eliminado')->send();
                $this->cargarHoy();
            });
    }

    /** Mismo criterio que editar/eliminar tanda, pero solo para quien tiene el nivel completo (jefe), igual que registrar la salida. */
    public function editarSalidaAction(): Action
    {
        return Action::make('editarSalida')
            ->label('Editar')->icon('heroicon-o-pencil-square')->color('gray')->size('sm')
            ->visible(fn (): bool => $this->puedeRegistrar() && $this->estado === 'borrador')
            ->modalHeading('Editar salida')
            ->modalWidth('lg')
            ->fillForm(function (Action $action): array {
                $salida = ProduccionDiariaSalida::findOrFail((int) ($action->getArguments()['salidaId'] ?? 0));

                return ['salida_id' => $salida->id, 'cantidad' => (float) $salida->cantidad, 'destino' => $salida->destino, 'nota' => $salida->nota];
            })
            ->schema([
                Hidden::make('salida_id')->required(),
                TextInput::make('cantidad')->label('Cantidad de salida')->numeric()->inputMode('decimal')->minValue(0.0001)->required(),
                Select::make('destino')->label('Destino')->native(false)->required()->options([
                    'despacho' => 'Área de despacho', 'merma' => 'Merma / descarte', 'ajuste' => 'Ajuste de conteo', 'otro' => 'Otro',
                ]),
                Textarea::make('nota')->label('Nota')->rows(2)->maxLength(500),
            ])
            ->action(function (array $data): void {
                abort_unless($this->puedeRegistrar(), 403);
                $cantidad = is_numeric($data['cantidad'] ?? null) ? (float) $data['cantidad'] : 0;
                if ($cantidad <= 0) throw ValidationException::withMessages(['cantidad' => 'Ingresa una cantidad mayor que cero.']);
                DB::transaction(function () use ($data, $cantidad): void {
                    $salida = ProduccionDiariaSalida::query()->whereHas('cierre', fn ($q) => $q->where('id', $this->cierreId)->where('estado', 'borrador'))
                        ->lockForUpdate()->findOrFail($data['salida_id']);
                    // La nueva cantidad no puede dejar el disponible en
                    // negativo -- se recalcula el disponible SIN esta salida
                    // (se descuenta aparte porque sigue existiendo mientras se
                    // valida) y se compara igual que al crearla.
                    $fecha = $this->fechaOperativa();
                    $inicial = $this->stockInicialAnterior($fecha, $salida->producto_id) ?? 0.0;
                    $producido = $this->totalProducido($fecha, $salida->producto_id);
                    $salidoSinEsta = $this->totalSalidas($fecha, $salida->producto_id) - (float) $salida->cantidad;
                    $disponibleSinEsta = round($inicial + $producido - $salidoSinEsta, 4);
                    if ($cantidad > $disponibleSinEsta + 0.0001) {
                        throw ValidationException::withMessages(['cantidad' => "No hay suficiente disponible: máximo {$this->formatearCantidad($disponibleSinEsta)} {$salida->unidad}."]);
                    }
                    $antes = $salida->only(['cantidad', 'destino', 'nota']);
                    $destino = (string) ($data['destino'] ?? $salida->destino);
                    $nota = trim((string) ($data['nota'] ?? '')) ?: null;
                    $salida->update(['cantidad' => $cantidad, 'destino' => $destino, 'nota' => $nota]);
                    $this->auditar($salida->cierre, 'salida_editada', ['salida' => ['id' => $salida->id, ...$antes]], ['salida' => ['id' => $salida->id, 'cantidad' => $cantidad, 'destino' => $destino, 'nota' => $nota]]);
                });
                Notification::make()->success()->title('Salida actualizada')->send();
                $this->cargarHoy();
            });
    }

    public function eliminarSalidaAction(): Action
    {
        return Action::make('eliminarSalida')
            ->label('Eliminar')->icon('heroicon-o-trash')->color('danger')->size('sm')
            ->visible(fn (): bool => $this->puedeRegistrar() && $this->estado === 'borrador')
            ->requiresConfirmation()
            ->modalHeading('¿Eliminar esta salida?')
            ->action(function (Action $action): void {
                abort_unless($this->puedeRegistrar(), 403);
                $salidaId = (int) ($action->getArguments()['salidaId'] ?? 0);
                DB::transaction(function () use ($salidaId): void {
                    $salida = ProduccionDiariaSalida::query()->whereHas('cierre', fn ($q) => $q->where('id', $this->cierreId)->where('estado', 'borrador'))
                        ->lockForUpdate()->findOrFail($salidaId);
                    $cierre = $salida->cierre;
                    $antes = ['salida' => $salida->only(['id', 'producto_id', 'item_nombre', 'cantidad', 'destino', 'nota'])];
                    $salida->delete();
                    $this->auditar($cierre, 'salida_eliminada', $antes, []);
                });
                Notification::make()->success()->title('Salida eliminada')->send();
                $this->cargarHoy();
            });
    }

    /**
     * Reabrir un cierre ya aprobado -- barrida de huecos funcionales
     * (2026-09-17): antes, si el jefe aprobaba por error, quedaba
     * bloqueado para siempre (soloLectura() nunca vuelve a false para un
     * cierre 'aprobado'), sin más salida que intervenir la base de datos a
     * mano. Solo quien puede aprobar puede reabrir -- mismo nivel de
     * responsabilidad. Vuelve a 'borrador' (no a 'enviado') para permitir
     * corregir cualquier cosa, incluidas tandas/salidas, antes de mandarlo
     * de nuevo a aprobación.
     */
    public function puedeReabrir(): bool { return $this->puedeAprobar() && $this->estado === 'aprobado'; }

    public function reabrir(): void
    {
        abort_unless($this->puedeAprobar(), 403);
        DB::transaction(function (): void {
            $cierre = ProduccionDiariaCierre::query()->lockForUpdate()->with('detalles')->findOrFail($this->cierreId);
            if ($cierre->estado !== 'aprobado') throw ValidationException::withMessages(['items' => 'Solo se puede reabrir un cierre aprobado.']);
            $antes = $this->snapshot($cierre);
            $cierre->update(['estado' => 'borrador', 'aprobado_por' => null, 'aprobado_en' => null, 'enviado_por' => null, 'enviado_en' => null]);
            $this->auditar($cierre, 'reabierto', $antes, $this->snapshot($cierre->fresh('detalles')));
        });
        Notification::make()->success()->title('Cierre reabierto')->body('Vuelve a estado borrador.')->send();
        $this->cargarHoy();
    }

    public function puedeRegistrar(): bool { return (bool) auth()->user()?->hasPermission('produccion-diaria.registrar'); }
    public function puedeAprobar(): bool { return (bool) auth()->user()?->hasPermission('produccion-diaria.aprobar'); }
    /**
     * Nivel angosto -- pedido explícito del usuario (2026-09-17): un
     * operario debe poder registrar SOLO tandas, sin salida ni cierre
     * físico. El nivel completo (puedeRegistrar) también cubre tandas --
     * un jefe con el permiso completo no pierde nada.
     */
    public function puedeRegistrarTanda(): bool { return $this->puedeRegistrar() || (bool) auth()->user()?->hasPermission('produccion-diaria.registrar-tanda'); }
    // soloLectura() se relaja para CUALQUIERA de los dos niveles de
    // registro (completo o solo-tanda) -- si solo mirara puedeRegistrar(),
    // un operario con el permiso angosto vería todo deshabilitado,
    // incluida la propia tanda que sí debería poder registrar.
    public function soloLectura(): bool { return ! ($this->puedeRegistrar() || $this->puedeRegistrarTanda()) || $this->estado === 'aprobado'; }
    public function etiquetaEstado(): string { return match ($this->estado) { 'borrador' => 'Borrador', 'enviado' => 'Enviado', 'aprobado' => 'Aprobado', default => 'Nuevo' }; }
    private function fechaOperativa(): string { return Carbon::now('America/Lima')->toDateString(); }

    private function cargarHoy(): void
    {
        $fecha = $this->fechaOperativa();
        $cierre = ProduccionDiariaCierre::query()->with('detalles')->whereDate('fecha', $fecha)->first();
        $this->cierreId = $cierre?->id;
        $this->estado = $cierre?->estado ?? 'nuevo';
        $items = $this->itemsParaFormulario($fecha, $cierre);
        $this->actualizarResumen($fecha, $items);
        $this->form->fill(['observacion' => $cierre?->observacion, 'items' => $items]);
        $this->verificarDiaAnterior($fecha);
    }

    /**
     * Aviso si el día anterior quedó sin cerrar/aprobar -- pedido explícito
     * del usuario (2026-09-17): antes, si nadie registraba nada un día
     * entero (o lo dejaba en borrador/enviado sin aprobar), el sistema no
     * avisaba -- el día siguiente simplemente tomaba como stock inicial el
     * último cierre APROBADO, sin importar hace cuántos días fue, en
     * silencio total. Chequeo simple y directo en pantalla (no depende de
     * un cron ni de que alguien revise notificaciones) -- se ve apenas se
     * entra al registro de hoy.
     */
    private function verificarDiaAnterior(string $fecha): void
    {
        $ayer = Carbon::parse($fecha)->subDay();
        $cierreAyer = ProduccionDiariaCierre::query()->whereDate('fecha', $ayer->toDateString())->first();
        $this->diaAnteriorFecha = $ayer->toDateString();
        $this->diaAnteriorSinCerrar = $cierreAyer === null;
        $this->diaAnteriorSinAprobar = $cierreAyer !== null && $cierreAyer->estado !== 'aprobado';
    }

    /** @return array<int, array<string, mixed>> */
    private function itemsParaFormulario(string $fecha, ?ProduccionDiariaCierre $cierre): array
    {
        $items = collect($this->itemsDesdeCatalogo($fecha))->keyBy('producto_id');
        foreach ($cierre?->detalles ?? [] as $detalle) {
            if (! $detalle->producto_id || ! $items->has($detalle->producto_id)) continue;
            $producido = $this->totalProducido($fecha, $detalle->producto_id);
            $salidas = $this->totalSalidas($fecha, $detalle->producto_id);
            $items->put($detalle->producto_id, ['producto_id' => $detalle->producto_id, 'item_codigo' => $detalle->item_codigo, 'item_nombre' => $detalle->item_nombre,
                'unidad' => $detalle->unidad, 'stock_inicial' => $detalle->stock_inicial, 'producido_hoy' => $producido, 'salidas_hoy' => $salidas,
                'stock_esperado' => round((float) $detalle->stock_inicial + $producido - $salidas, 4), 'stock_final' => $detalle->stock_final,
                'diferencia' => $detalle->diferencia, 'observacion' => $detalle->observacion, 'origen_inicial' => 'registro_guardado']);
        }
        return $items->values()->all();
    }

    /**
     * Catálogo de HOY = productos activos + cualquier producto que ya
     * tenga tanda o salida registrada hoy, esté activo o no -- barrida de
     * huecos funcionales (2026-09-17): si alguien desactiva un producto a
     * mitad del día (Productos de producción), antes desaparecía en
     * silencio de la pantalla y de esta misma consulta, y su producción ya
     * registrada quedaba fuera del cierre para siempre (nunca generaba
     * fila de detalle). Ahora, aunque esté inactivo, sigue apareciendo
     * MIENTRAS tenga movimiento hoy, para que el cierre lo concilie.
     *
     * @return \Illuminate\Support\Collection<int, ProduccionProducto>
     */
    private function productosDelDia(string $fecha): \Illuminate\Support\Collection
    {
        $idsConMovimientoHoy = ProduccionDiariaTanda::query()->whereHas('cierre', fn ($q) => $q->whereDate('fecha', $fecha))->whereNotNull('producto_id')->pluck('producto_id')
            ->merge(ProduccionDiariaSalida::query()->whereHas('cierre', fn ($q) => $q->whereDate('fecha', $fecha))->whereNotNull('producto_id')->pluck('producto_id'))
            ->unique();

        return ProduccionProducto::query()->with('categoria')
            ->where(fn ($q) => $q->where('activo', true)->orWhereIn('id', $idsConMovimientoHoy))
            ->orderBy('nombre')->get();
    }

    /** @return array<int, array<string, mixed>> */
    private function itemsDesdeCatalogo(string $fecha): array
    {
        return $this->productosDelDia($fecha)->map(function (ProduccionProducto $producto) use ($fecha): array {
            $inicial = $this->stockInicialAnterior($fecha, $producto->id);
            $producido = $this->totalProducido($fecha, $producto->id);
            $salidas = $this->totalSalidas($fecha, $producto->id);
            return ['producto_id' => $producto->id, 'item_codigo' => $producto->codigo, 'item_nombre' => $producto->nombre, 'unidad' => $producto->unidad,
                'categoria' => $producto->categoria?->nombre ?? 'Sin categoría', 'orden_categoria' => $producto->categoria?->orden ?? PHP_INT_MAX,
                'stock_inicial' => $inicial ?? 0, 'producido_hoy' => $producido, 'salidas_hoy' => $salidas, 'stock_esperado' => round(($inicial ?? 0) + $producido - $salidas, 4),
                'stock_final' => null, 'diferencia' => null, 'observacion' => null, 'origen_inicial' => $inicial === null ? 'apertura' : 'cierre_anterior'];
        })->all();
    }

    /** @param array<int, array<string, mixed>> $items */
    private function actualizarResumen(string $fecha, array $items): void
    {
        $catalogo = collect($items)->keyBy('producto_id');
        $grupos = ProduccionDiariaTanda::query()->whereHas('cierre', fn ($q) => $q->whereDate('fecha', $fecha))->whereNotNull('producto_id')
            ->selectRaw('producto_id, SUM(cantidad) as cantidad, COUNT(*) as tandas')->groupBy('producto_id')->get();
        $this->resumenProduccion = $grupos->map(function (ProduccionDiariaTanda $tanda) use ($catalogo): array {
            $item = $catalogo->get($tanda->producto_id, []);
            return ['nombre' => $item['item_nombre'] ?? $tanda->item_nombre, 'codigo' => $item['item_codigo'] ?? $tanda->item_codigo,
                'unidad' => $item['unidad'] ?? $tanda->unidad ?: 'UNIDAD', 'cantidad' => (float) $tanda->cantidad, 'tandas' => (int) $tanda->tandas];
        })->sortByDesc('cantidad')->values()->all();
        $tandasPorProducto = $grupos->keyBy('producto_id');
        $productos = $catalogo->map(function (array $item, int $productoId) use ($tandasPorProducto): array {
            $tanda = $tandasPorProducto->get($productoId);
            return [
                'id' => $productoId,
                'codigo' => $item['item_codigo'] ?? '',
                'nombre' => $item['item_nombre'],
                'categoria' => $item['categoria'] ?? 'Sin categoría',
                'orden_categoria' => $item['orden_categoria'] ?? PHP_INT_MAX,
                'unidad' => $item['unidad'] ?? 'UNIDAD',
                'stock_inicial' => (float) ($item['stock_inicial'] ?? 0),
                'producido_hoy' => (float) ($item['producido_hoy'] ?? 0),
                'salidas_hoy' => (float) ($item['salidas_hoy'] ?? 0),
                'disponible' => (float) ($item['stock_esperado'] ?? 0),
                'tandas' => (int) ($tanda?->tandas ?? 0),
            ];
        })->sortBy([['orden_categoria', 'asc'], ['nombre', 'asc']])->values();
        $this->productosParaRegistro = $productos->all();
        $this->productosPorCategoria = $productos->groupBy('categoria')->map(fn ($items): array => $items->values()->all())->all();
        $this->tandasRecientes = ProduccionDiariaTanda::query()->with('registrador')->whereHas('cierre', fn ($q) => $q->whereDate('fecha', $fecha))->latest('created_at')->limit(8)->get()
            ->map(fn (ProduccionDiariaTanda $t): array => ['id' => $t->id, 'producto' => $t->item_nombre, 'cantidad' => (float) $t->cantidad, 'unidad' => $t->unidad ?: 'UNIDAD',
                'nota' => $t->nota, 'hora' => $t->created_at?->timezone('America/Lima')->format('H:i'), 'usuario' => $t->registrador?->name])->all();
        $this->salidasRecientes = ProduccionDiariaSalida::query()->with('registrador')->whereHas('cierre', fn ($q) => $q->whereDate('fecha', $fecha))->latest('created_at')->limit(8)->get()
            ->map(fn (ProduccionDiariaSalida $s): array => ['id' => $s->id, 'producto' => $s->item_nombre, 'cantidad' => (float) $s->cantidad, 'unidad' => $s->unidad ?: 'UNIDAD',
                'destino' => $s->destino, 'nota' => $s->nota, 'hora' => $s->created_at?->timezone('America/Lima')->format('H:i'), 'usuario' => $s->registrador?->name])->all();
    }

    /** @return array{stock_inicial: float, producido_hoy: float, disponible: float} */
    private function resumenProducto(int $productoId): array
    {
        $item = collect($this->productosParaRegistro)->firstWhere('id', $productoId);
        if (! $item) return ['stock_inicial' => 0, 'producido_hoy' => 0, 'disponible' => 0];

        return ['stock_inicial' => (float) $item['stock_inicial'], 'producido_hoy' => (float) $item['producido_hoy'], 'disponible' => (float) $item['disponible']];
    }

    private function productoDeAccion(Action $action): ProduccionProducto
    {
        $productoId = (int) ($action->getArguments()['productoId'] ?? 0);

        return ProduccionProducto::query()->where('activo', true)->findOrFail($productoId);
    }

    private function formatearCantidad(float $cantidad): string
    {
        return number_format($cantidad, 2, '.', '');
    }

    /** @param array<string, mixed>|null $state */
    private function guardar(string $destino, ?array $state = null): void
    {
        abort_unless($this->puedeRegistrar(), 403);
        if ($this->soloLectura()) throw ValidationException::withMessages(['items' => 'El cierre aprobado no puede modificarse.']);
        try {
            $this->intentoGuardar = $destino;
            $state ??= $this->form->getState();
        } finally {
            $this->intentoGuardar = 'borrador';
        }
        $fecha = $this->fechaOperativa(); $items = $this->normalizarItems($fecha, (array) ($state['items'] ?? []), $destino === 'enviado');
        DB::transaction(function () use ($fecha, $state, $items, $destino): void {
            $cierre = ProduccionDiariaCierre::query()->whereDate('fecha', $fecha)->lockForUpdate()->with('detalles')->first();
            if ($cierre?->estado === 'aprobado') throw ValidationException::withMessages(['items' => 'El cierre ya fue aprobado.']);
            $antes = $cierre ? $this->snapshot($cierre) : null;
            $cierre ??= new ProduccionDiariaCierre(['fecha' => $fecha, 'area' => 'FABRICA', 'creado_por' => auth()->id()]);
            $cierre->fill(['estado' => $destino, 'observacion' => $state['observacion'] ?? null]);
            if ($destino === 'enviado') $cierre->fill(['enviado_por' => auth()->id(), 'enviado_en' => now()]);
            $cierre->save(); $cierre->detalles()->delete(); $cierre->detalles()->createMany($items);
            $this->auditar($cierre, $antes ? ($destino === 'enviado' ? 'enviado' : 'actualizado') : 'creado', $antes, $this->snapshot($cierre->fresh('detalles')));
        });
        Notification::make()->success()->title($destino === 'enviado' ? 'Cierre enviado para aprobación' : 'Borrador guardado')->send(); $this->cargarHoy();
    }

    /** @param array<int, array<string, mixed>> $items @return array<int, array<string, mixed>> */
    private function normalizarItems(string $fecha, array $items, bool $requiereFinal): array
    {
        if ($items === []) throw ValidationException::withMessages(['items' => 'No hay productos activos en el catálogo de Producción.']);
        $catalogo = $this->productosDelDia($fecha)->keyBy('id');
        return collect($items)->map(function (array $item) use ($fecha, $catalogo, $requiereFinal): array {
            $producto = $catalogo->get($item['producto_id'] ?? null);
            if (! $producto) throw ValidationException::withMessages(['items' => 'El producto no pertenece al catálogo de Producción de hoy.']);
            $inicialAnterior = $this->stockInicialAnterior($fecha, $producto->id);
            $inicial = $inicialAnterior ?? (float) ($item['stock_inicial'] ?? 0);
            $producido = $this->totalProducido($fecha, $producto->id);
            $salidas = $this->totalSalidas($fecha, $producto->id);
            $final = filled($item['stock_final'] ?? null) ? (float) $item['stock_final'] : null;
            if ($inicial < 0 || ($final !== null && $final < 0)) throw ValidationException::withMessages(['items' => 'Las cantidades no pueden ser negativas.']);
            if ($requiereFinal && $final === null) throw ValidationException::withMessages(['items' => "{$producto->nombre}: registra el stock final físico."]);
            $esperado = round($inicial + $producido - $salidas, 4); $diferencia = $final === null ? null : round($final - $esperado, 4);
            $observacion = trim((string) ($item['observacion'] ?? '')) ?: null;
            if ($requiereFinal && $diferencia !== null && abs($diferencia) > 0.0001 && $observacion === null) throw ValidationException::withMessages(['items' => "{$producto->nombre}: indica el motivo de la diferencia."]);
            return ['producto_id' => $producto->id, 'item_id' => (string) $producto->id, 'item_tipo' => 'produccion', 'item_codigo' => $producto->codigo,
                'item_nombre' => $producto->nombre, 'unidad' => $producto->unidad, 'stock_inicial' => $inicial, 'producido_hoy' => $producido, 'salidas_hoy' => $salidas,
                'stock_esperado' => $esperado, 'stock_final' => $final, 'diferencia' => $diferencia, 'observacion' => $observacion];
        })->values()->all();
    }

    private function totalProducido(string $fecha, int $productoId): float
    {
        return (float) ProduccionDiariaTanda::query()->whereHas('cierre', fn ($q) => $q->whereDate('fecha', $fecha))->where('producto_id', $productoId)->sum('cantidad');
    }

    private function totalSalidas(string $fecha, int $productoId): float
    {
        return (float) ProduccionDiariaSalida::query()->whereHas('cierre', fn ($q) => $q->whereDate('fecha', $fecha))->where('producto_id', $productoId)->sum('cantidad');
    }


    private function stockInicialAnterior(string $fecha, int $productoId): ?float
    {
        $value = ProduccionDiariaDetalle::query()->select('produccion_diaria_detalles.stock_final')->join('produccion_diaria_cierres as cierres', 'cierres.id', '=', 'produccion_diaria_detalles.cierre_id')
            ->where('produccion_diaria_detalles.producto_id', $productoId)->where('cierres.estado', 'aprobado')->whereDate('cierres.fecha', '<', $fecha)
            ->whereNotNull('produccion_diaria_detalles.stock_final')->orderByDesc('cierres.fecha')->value('produccion_diaria_detalles.stock_final');
        return $value === null ? null : (float) $value;
    }

    private function actualizarCalculos(Get $get, Set $set): void
    {
        $esperado = round((float) ($get('stock_inicial') ?? 0) + (float) ($get('producido_hoy') ?? 0) - (float) ($get('salidas_hoy') ?? 0), 4); $final = $get('stock_final');
        $set('stock_esperado', $esperado); $set('diferencia', filled($final) ? round((float) $final - $esperado, 4) : null);
    }

    private function snapshot(ProduccionDiariaCierre $cierre): array
    {
        return ['fecha' => $cierre->fecha?->toDateString(), 'estado' => $cierre->estado, 'observacion' => $cierre->observacion,
            'detalles' => $cierre->detalles->map(fn (ProduccionDiariaDetalle $d): array => $d->only(['producto_id', 'stock_inicial', 'producido_hoy', 'salidas_hoy', 'stock_esperado', 'stock_final', 'diferencia', 'observacion']))->all()];
    }

    private function auditar(ProduccionDiariaCierre $cierre, string $accion, ?array $antes, array $despues): void
    {
        ProduccionDiariaAuditoria::create(['cierre_id' => $cierre->id, 'accion' => $accion, 'antes' => $antes, 'despues' => $despues, 'usuario_id' => auth()->id()]);
    }
}
