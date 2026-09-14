<?php

namespace App\Filament\Pages\Produccion;

use App\Models\DirectivaTransferenciaSugerencia;
use App\Models\KardexMovimiento;
use App\Models\ProduccionDiariaAuditoria;
use App\Models\ProduccionDiariaCierre;
use App\Models\ProduccionDiariaDetalle;
use App\Models\ProduccionDiariaTanda;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Registro táctil de tandas; la DT define productos y el cierre concilia stock. */
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
    public ?int $cierreId = null;
    public string $estado = 'nuevo';
    public bool $mostrarCierre = false;
    private string $intentoGuardar = 'borrador';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->hasPermission('produccion-diaria.view')
            || $user?->hasPermission('produccion-diaria.registrar')
            || $user?->hasPermission('produccion-diaria.aprobar'));
    }

    public function mount(): void
    {
        $this->cargarHoy();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Registrar tanda')->description('Elige el producto e ingresa solo la cantidad producida. La fecha y hora se registran automáticamente.')->compact()->schema([
                Grid::make(['default' => 1, 'md' => 12])->schema([
                    TextInput::make('fecha_visible')->label('Fecha de registro')->readOnly()->dehydrated(false)->columnSpan(['md' => 2]),
                    TextInput::make('estado_actual')->label('Estado')->readOnly()->dehydrated(false)->columnSpan(['md' => 2]),
                    Select::make('tanda.producto')->label('Producto')->options(fn (): array => $this->opcionesProductos())->searchable()->preload()->native(false)
                        ->placeholder('Toca para buscar un producto')->disabled(fn (): bool => $this->soloLectura())->columnSpan(['md' => 4]),
                    TextInput::make('tanda.cantidad')->label('Cantidad producida')->numeric()->minValue(0.0001)->inputMode('decimal')
                        ->placeholder('0')->disabled(fn (): bool => $this->soloLectura())->columnSpan(['md' => 2]),
                    Textarea::make('tanda.nota')->label('Nota (responsable / turno)')->rows(1)->maxLength(500)
                        ->placeholder('Ej.: María · turno mañana')->disabled(fn (): bool => $this->soloLectura())->columnSpan(['md' => 12]),
                ]),
            ]),
            Section::make('Conciliación de cierre')->description('Al terminar el día registra el stock físico y envíalo a aprobación.')
                ->compact()->visible(fn (): bool => $this->mostrarCierre)->schema([
                    Textarea::make('observacion')->label('Observación general')->rows(2)->maxLength(1000)->columnSpanFull()->disabled(fn (): bool => $this->soloLectura()),
                    Repeater::make('items')->label('')->hiddenLabel()->addable(false)->deletable(false)->reorderable(false)->itemNumbers(false)->compact()
                        ->columns(['default' => 1, 'md' => 12])->schema([
                            Hidden::make('item_id')->dehydrated(),
                            Hidden::make('item_tipo')->dehydrated(),
                            Hidden::make('origen_inicial')->dehydrated(),
                            TextInput::make('item_codigo')->label('Código')->readOnly()->dehydrated()->columnSpan(['md' => 1]),
                            TextInput::make('item_nombre')->label('Producto')->readOnly()->dehydrated()->columnSpan(['md' => 3]),
                            TextInput::make('unidad')->label('Unidad')->readOnly()->dehydrated()->columnSpan(['md' => 1]),
                            TextInput::make('stock_inicial')->label('Stock inicial')->numeric()->minValue(0)->required()->live()
                                ->readOnly(fn (Get $get): bool => $get('origen_inicial') === 'cierre_anterior')->disabled(fn (): bool => $this->soloLectura())
                                ->afterStateUpdated(fn (Get $get, Set $set) => $this->actualizarCalculos($get, $set))->columnSpan(['md' => 1]),
                            TextInput::make('producido_hoy')->label('Acumulado producido')->numeric()->readOnly()->dehydrated()->columnSpan(['md' => 1]),
                            TextInput::make('stock_esperado')->label('Stock esperado')->numeric()->readOnly()->dehydrated()->columnSpan(['md' => 1]),
                            TextInput::make('stock_final')->label('Stock final físico')->numeric()->minValue(0)->inputMode('decimal')->live()
                                ->required(fn (): bool => $this->intentoGuardar === 'enviado')->disabled(fn (): bool => $this->soloLectura())
                                ->afterStateUpdated(fn (Get $get, Set $set) => $this->actualizarCalculos($get, $set))->columnSpan(['md' => 1]),
                            TextInput::make('diferencia')->label('Diferencia')->numeric()->readOnly()->dehydrated()->columnSpan(['md' => 1]),
                            Textarea::make('observacion')->label('Motivo de diferencia')->rows(1)->maxLength(500)
                                ->required(fn (Get $get): bool => $this->intentoGuardar === 'enviado' && abs((float) ($get('diferencia') ?? 0)) > 0.0001)
                                ->disabled(fn (): bool => $this->soloLectura())->columnSpan(['md' => 12]),
                        ]),
                ]),
        ])->statePath('data');
    }

    public function registrarTanda(): void
    {
        abort_unless($this->puedeRegistrar(), 403);
        $fecha = $this->fechaOperativa();
        $tanda = (array) ($this->data['tanda'] ?? []);
        $producto = $this->productoDtPorClave($fecha, (string) ($tanda['producto'] ?? ''));
        $cantidad = is_numeric($tanda['cantidad'] ?? null) ? (float) $tanda['cantidad'] : 0;
        $nota = trim((string) ($tanda['nota'] ?? '')) ?: null;

        if (! $producto) {
            throw ValidationException::withMessages(['data.tanda.producto' => 'Selecciona un producto válido de la DT de hoy.']);
        }
        if ($cantidad <= 0) {
            throw ValidationException::withMessages(['data.tanda.cantidad' => 'Ingresa una cantidad mayor que cero.']);
        }

        DB::transaction(function () use ($fecha, $producto, $cantidad, $nota): void {
            $cierre = ProduccionDiariaCierre::query()->whereDate('fecha', $fecha)->lockForUpdate()->first();
            if ($cierre?->estado === 'aprobado') {
                throw ValidationException::withMessages(['data.tanda.producto' => 'El cierre de hoy ya está aprobado; no se pueden agregar tandas.']);
            }
            if ($cierre?->estado === 'enviado') {
                throw ValidationException::withMessages(['data.tanda.producto' => 'El cierre está enviado. Regrésalo a borrador antes de registrar una nueva tanda.']);
            }

            $cierre ??= new ProduccionDiariaCierre(['fecha' => $fecha, 'area' => 'FABRICA', 'estado' => 'borrador', 'creado_por' => auth()->id()]);
            $cierre->save();
            $tandaCreada = $cierre->tandas()->create([
                'item_id' => $producto->item_id, 'item_tipo' => $producto->item_tipo, 'item_codigo' => $producto->item_codigo,
                'item_nombre' => $producto->item_nombre, 'unidad' => $this->unidadProducto($producto), 'cantidad' => $cantidad,
                'nota' => $nota, 'registrado_por' => auth()->id(),
            ]);
            $this->auditar($cierre, 'tanda_registrada', null, ['tanda' => [
                'id' => $tandaCreada->id, 'producto' => $tandaCreada->item_nombre,
                'cantidad' => (float) $tandaCreada->cantidad, 'nota' => $tandaCreada->nota,
            ]]);
        });

        Notification::make()->success()->title('Tanda registrada')->body(number_format($cantidad, 2).' '.$this->unidadProducto($producto).' de '.$producto->item_nombre)->send();
        $this->cargarHoy();
    }

    public function abrirCierreFisico(): void
    {
        $this->mostrarCierre = true;
        $this->cargarHoy();
    }

    public function guardarBorrador(): void { $this->guardar('borrador'); }
    public function enviarCierre(): void { $this->guardar('enviado'); }

    public function aprobar(): void
    {
        abort_unless((bool) auth()->user()?->hasPermission('produccion-diaria.aprobar'), 403);
        DB::transaction(function (): void {
            $cierre = ProduccionDiariaCierre::query()->lockForUpdate()->with('detalles')->findOrFail($this->cierreId);
            if ($cierre->estado !== 'enviado') {
                throw ValidationException::withMessages(['items' => 'Solo se puede aprobar un cierre enviado.']);
            }
            foreach ($cierre->detalles as $detalle) {
                if ($detalle->stock_final === null) {
                    throw ValidationException::withMessages(['items' => 'Todos los productos deben tener stock final físico antes de aprobar.']);
                }
                if (abs((float) $detalle->diferencia) > 0.0001 && blank($detalle->observacion)) {
                    throw ValidationException::withMessages(['items' => "{$detalle->item_nombre}: registra el motivo de la diferencia."]);
                }
            }
            $antes = $this->snapshot($cierre);
            $cierre->update(['estado' => 'aprobado', 'aprobado_por' => auth()->id(), 'aprobado_en' => now()]);
            $this->auditar($cierre, 'aprobado', $antes, $this->snapshot($cierre->fresh('detalles')));
        });

        Notification::make()->success()->title('Cierre aprobado')->body('El stock final aprobado alimentará el stock inicial del próximo registro. Kardex no fue modificado.')->send();
        $this->cargarHoy();
    }

    public function puedeRegistrar(): bool { return (bool) auth()->user()?->hasPermission('produccion-diaria.registrar'); }
    public function puedeAprobar(): bool { return (bool) auth()->user()?->hasPermission('produccion-diaria.aprobar'); }
    public function soloLectura(): bool { return ! $this->puedeRegistrar() || $this->estado === 'aprobado'; }
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
        $this->form->fill([
            'fecha_visible' => Carbon::parse($fecha)->locale('es')->isoFormat('dddd D [de] MMMM'),
            'estado_actual' => $this->etiquetaEstado(), 'observacion' => $cierre?->observacion,
            'tanda' => ['producto' => null, 'cantidad' => null, 'nota' => null], 'items' => $items,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function itemsParaFormulario(string $fecha, ?ProduccionDiariaCierre $cierre): array
    {
        $items = collect($this->itemsDesdeDt($fecha))->keyBy(fn (array $item): string => $this->claveProducto($item['item_id'], $item['item_tipo']));
        foreach ($cierre?->detalles ?? [] as $detalle) {
            $key = $this->claveProducto($detalle->item_id, $detalle->item_tipo);
            if ($items->has($key)) {
                $producido = $this->totalProducido($fecha, $detalle->item_id, $detalle->item_tipo);
                $items->put($key, ['item_id' => $detalle->item_id, 'item_tipo' => $detalle->item_tipo, 'item_codigo' => $detalle->item_codigo,
                    'item_nombre' => $detalle->item_nombre, 'unidad' => $detalle->unidad, 'stock_inicial' => $detalle->stock_inicial,
                    'producido_hoy' => $producido, 'stock_esperado' => round((float) $detalle->stock_inicial + $producido, 4),
                    'stock_final' => $detalle->stock_final, 'diferencia' => $detalle->diferencia, 'observacion' => $detalle->observacion,
                    'origen_inicial' => 'registro_guardado']);
            }
        }
        return $items->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function itemsDesdeDt(string $fecha): array
    {
        return $this->productosDt($fecha)->map(function (DirectivaTransferenciaSugerencia $row) use ($fecha): array {
            $inicial = $this->stockInicialAnterior($fecha, (string) $row->item_id, $row->item_tipo);
            $producido = $this->totalProducido($fecha, (string) $row->item_id, $row->item_tipo);
            return ['item_id' => (string) $row->item_id, 'item_tipo' => $row->item_tipo, 'item_codigo' => $row->item_codigo,
                'item_nombre' => $row->item_nombre, 'unidad' => $this->unidadProducto($row), 'stock_inicial' => $inicial ?? 0,
                'producido_hoy' => $producido, 'stock_esperado' => round(($inicial ?? 0) + $producido, 4), 'stock_final' => null,
                'diferencia' => null, 'observacion' => null, 'origen_inicial' => $inicial === null ? 'apertura' : 'cierre_anterior'];
        })->values()->all();
    }

    /** @return Collection<int, DirectivaTransferenciaSugerencia> */
    private function productosDt(string $fecha): Collection
    {
        return DirectivaTransferenciaSugerencia::query()->whereDate('fecha_despacho', $fecha)->where('cantidad_sugerida', '>', 0)
            ->orderBy('item_nombre')->get()->unique(fn (DirectivaTransferenciaSugerencia $row): string => $this->claveProducto($row->item_id, $row->item_tipo))->values();
    }

    /** @return array<string, string> */
    private function opcionesProductos(): array
    {
        return $this->productosDt($this->fechaOperativa())->mapWithKeys(fn (DirectivaTransferenciaSugerencia $row): array => [
            $this->claveProducto($row->item_id, $row->item_tipo) => trim(($row->item_codigo ? $row->item_codigo.' · ' : '').$row->item_nombre.' · '.$this->unidadProducto($row)),
        ])->all();
    }

    private function productoDtPorClave(string $fecha, string $clave): ?DirectivaTransferenciaSugerencia
    {
        return $this->productosDt($fecha)->first(fn (DirectivaTransferenciaSugerencia $row): bool => $this->claveProducto($row->item_id, $row->item_tipo) === $clave);
    }

    private function claveProducto(string|int $itemId, ?string $itemTipo): string { return (string) $itemId.'|'.($itemTipo ?? ''); }
    private function unidadProducto(DirectivaTransferenciaSugerencia $producto): string { return KardexMovimiento::query()->where('item_id', $producto->item_id)->latest('fecha_hora')->value('unidad_medida') ?: 'UNIDAD'; }

    /** @param array<int, array<string, mixed>> $items */
    private function actualizarResumen(string $fecha, array $items): void
    {
        $porProducto = ProduccionDiariaTanda::query()->whereHas('cierre', fn ($query) => $query->whereDate('fecha', $fecha))
            ->selectRaw('item_id, item_tipo, SUM(cantidad) as cantidad, COUNT(*) as tandas')->groupBy('item_id', 'item_tipo')->get()
            ->keyBy(fn (ProduccionDiariaTanda $tanda): string => $this->claveProducto($tanda->item_id, $tanda->item_tipo));
        $porDetalle = collect($items)->keyBy(fn (array $item): string => $this->claveProducto($item['item_id'], $item['item_tipo']));
        $this->resumenProduccion = $porProducto->map(function (ProduccionDiariaTanda $tanda, string $clave) use ($porDetalle): array {
            $item = $porDetalle->get($clave, []);
            return ['nombre' => $item['item_nombre'] ?? $tanda->item_nombre, 'codigo' => $item['item_codigo'] ?? $tanda->item_codigo,
                'unidad' => $item['unidad'] ?? $tanda->unidad ?? 'UNIDAD', 'cantidad' => (float) $tanda->cantidad, 'tandas' => (int) $tanda->tandas];
        })->sortByDesc('cantidad')->values()->all();
        $this->tandasRecientes = ProduccionDiariaTanda::query()->whereHas('cierre', fn ($query) => $query->whereDate('fecha', $fecha))
            ->latest('created_at')->limit(8)->get()->map(fn (ProduccionDiariaTanda $tanda): array => [
                'producto' => $tanda->item_nombre, 'cantidad' => (float) $tanda->cantidad, 'unidad' => $tanda->unidad ?: 'UNIDAD',
                'nota' => $tanda->nota, 'hora' => $tanda->created_at?->timezone('America/Lima')->format('H:i')])->all();
    }

    private function guardar(string $destino): void
    {
        abort_unless($this->puedeRegistrar(), 403);
        if ($this->soloLectura()) throw ValidationException::withMessages(['items' => 'El cierre aprobado no puede modificarse.']);
        $this->intentoGuardar = $destino;
        $state = $this->form->getState();
        $this->intentoGuardar = 'borrador';
        $fecha = $this->fechaOperativa();
        $items = $this->normalizarItems($fecha, (array) ($state['items'] ?? []), $destino === 'enviado');
        DB::transaction(function () use ($fecha, $state, $items, $destino): void {
            $cierre = ProduccionDiariaCierre::query()->whereDate('fecha', $fecha)->lockForUpdate()->with('detalles')->first();
            if ($cierre?->estado === 'aprobado') throw ValidationException::withMessages(['items' => 'El cierre ya fue aprobado y no puede sobrescribirse.']);
            $antes = $cierre ? $this->snapshot($cierre) : null;
            $cierre ??= new ProduccionDiariaCierre(['fecha' => $fecha, 'area' => 'FABRICA', 'creado_por' => auth()->id()]);
            $cierre->fill(['estado' => $destino, 'observacion' => $state['observacion'] ?? null]);
            if ($destino === 'enviado') $cierre->fill(['enviado_por' => auth()->id(), 'enviado_en' => now()]);
            $cierre->save();
            $cierre->detalles()->delete();
            $cierre->detalles()->createMany($items);
            $this->auditar($cierre, $antes ? ($destino === 'enviado' ? 'enviado' : 'actualizado') : 'creado', $antes, $this->snapshot($cierre->fresh('detalles')));
        });
        Notification::make()->success()->title($destino === 'enviado' ? 'Cierre enviado para aprobación' : 'Borrador guardado')->send();
        $this->cargarHoy();
    }

    /** @param array<int, array<string, mixed>> $items @return array<int, array<string, mixed>> */
    private function normalizarItems(string $fecha, array $items, bool $requiereFinal): array
    {
        if ($items === []) throw ValidationException::withMessages(['items' => 'No existen productos con cantidad sugerida en la DT de hoy.']);
        $dt = $this->productosDt($fecha)->keyBy(fn (DirectivaTransferenciaSugerencia $row): string => $this->claveProducto($row->item_id, $row->item_tipo));
        if ($dt->isEmpty()) throw ValidationException::withMessages(['items' => 'La DT de hoy no tiene productos para registrar.']);
        return collect($items)->map(function (array $item) use ($fecha, $dt, $requiereFinal): array {
            $key = $this->claveProducto((string) ($item['item_id'] ?? ''), $item['item_tipo'] ?? null);
            $fuente = $dt->get($key);
            if (! $fuente) throw ValidationException::withMessages(['items' => 'Solo se pueden cerrar productos que pertenezcan a la DT de hoy.']);
            $inicialAnterior = $this->stockInicialAnterior($fecha, (string) $fuente->item_id, $fuente->item_tipo);
            $inicial = $inicialAnterior ?? (float) ($item['stock_inicial'] ?? 0);
            $producido = $this->totalProducido($fecha, (string) $fuente->item_id, $fuente->item_tipo);
            $final = filled($item['stock_final'] ?? null) ? (float) $item['stock_final'] : null;
            if ($inicial < 0 || ($final !== null && $final < 0)) throw ValidationException::withMessages(['items' => 'Las cantidades no pueden ser negativas.']);
            if ($requiereFinal && $final === null) throw ValidationException::withMessages(['items' => "{$fuente->item_nombre}: registra el stock final físico."]);
            $esperado = round($inicial + $producido, 4);
            $diferencia = $final === null ? null : round($final - $esperado, 4);
            $observacion = trim((string) ($item['observacion'] ?? '')) ?: null;
            if ($requiereFinal && $diferencia !== null && abs($diferencia) > 0.0001 && $observacion === null) throw ValidationException::withMessages(['items' => "{$fuente->item_nombre}: indica el motivo de la diferencia."]);
            return ['item_id' => $fuente->item_id, 'item_tipo' => $fuente->item_tipo, 'item_codigo' => $fuente->item_codigo,
                'item_nombre' => $fuente->item_nombre, 'unidad' => $item['unidad'] ?? $this->unidadProducto($fuente),
                'stock_inicial' => $inicial, 'producido_hoy' => $producido, 'stock_esperado' => $esperado,
                'stock_final' => $final, 'diferencia' => $diferencia, 'observacion' => $observacion];
        })->values()->all();
    }

    private function totalProducido(string $fecha, string $itemId, ?string $itemTipo): float
    {
        $query = ProduccionDiariaTanda::query()->whereHas('cierre', fn ($builder) => $builder->whereDate('fecha', $fecha))->where('item_id', $itemId);
        $itemTipo === null ? $query->whereNull('item_tipo') : $query->where('item_tipo', $itemTipo);
        return (float) $query->sum('cantidad');
    }

    private function stockInicialAnterior(string $fecha, string $itemId, ?string $itemTipo): ?float
    {
        $query = ProduccionDiariaDetalle::query()->select('produccion_diaria_detalles.stock_final')->join('produccion_diaria_cierres as cierres', 'cierres.id', '=', 'produccion_diaria_detalles.cierre_id')
            ->where('produccion_diaria_detalles.item_id', $itemId)->where('cierres.estado', 'aprobado')->whereDate('cierres.fecha', '<', $fecha)->whereNotNull('produccion_diaria_detalles.stock_final')->orderByDesc('cierres.fecha');
        $itemTipo === null ? $query->whereNull('produccion_diaria_detalles.item_tipo') : $query->where('produccion_diaria_detalles.item_tipo', $itemTipo);
        $value = $query->value('produccion_diaria_detalles.stock_final');
        return $value === null ? null : (float) $value;
    }

    private function actualizarCalculos(Get $get, Set $set): void
    {
        $esperado = round((float) ($get('stock_inicial') ?? 0) + (float) ($get('producido_hoy') ?? 0), 4);
        $final = $get('stock_final');
        $set('stock_esperado', $esperado);
        $set('diferencia', filled($final) ? round((float) $final - $esperado, 4) : null);
    }

    private function snapshot(ProduccionDiariaCierre $cierre): array
    {
        return ['fecha' => $cierre->fecha?->toDateString(), 'estado' => $cierre->estado, 'observacion' => $cierre->observacion,
            'detalles' => $cierre->detalles->map(fn (ProduccionDiariaDetalle $d): array => $d->only(['item_id', 'item_tipo', 'stock_inicial', 'producido_hoy', 'stock_esperado', 'stock_final', 'diferencia', 'observacion']))->all()];
    }

    private function auditar(ProduccionDiariaCierre $cierre, string $accion, ?array $antes, array $despues): void
    {
        ProduccionDiariaAuditoria::create(['cierre_id' => $cierre->id, 'accion' => $accion, 'antes' => $antes, 'despues' => $despues, 'usuario_id' => auth()->id()]);
    }
}
