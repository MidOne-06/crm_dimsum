<?php

namespace App\Filament\Pages\Produccion;

use App\Models\ProduccionDiariaAuditoria;
use App\Models\ProduccionDiariaCierre;
use App\Models\ProduccionDiariaDetalle;
use App\Models\ProduccionDiariaTanda;
use App\Models\ProduccionProducto;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
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
    public array $productosParaRegistro = [];
    public ?int $cierreId = null;
    public string $estado = 'nuevo';
    public bool $mostrarCierre = false;
    private string $intentoGuardar = 'borrador';

    public static function canAccess(): bool
    {
        $user = auth()->user();
        return (bool) ($user?->hasPermission('produccion-diaria.view') || $user?->hasPermission('produccion-diaria.registrar') || $user?->hasPermission('produccion-diaria.aprobar'));
    }

    public function mount(): void { $this->cargarHoy(); }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Conciliación de cierre')->compact()->visible(fn (): bool => $this->mostrarCierre)->schema([
                Textarea::make('observacion')->label('Observación general')->rows(2)->maxLength(1000)->columnSpanFull()->disabled(fn (): bool => $this->soloLectura()),
                Repeater::make('items')->label('')->hiddenLabel()->addable(false)->deletable(false)->reorderable(false)->itemNumbers(false)->compact()->columns(['default' => 1, 'md' => 12])->schema([
                    Hidden::make('producto_id')->dehydrated(),
                    Hidden::make('origen_inicial')->dehydrated(),
                    TextInput::make('item_codigo')->label('Código')->readOnly()->dehydrated()->columnSpan(['md' => 1]),
                    TextInput::make('item_nombre')->label('Producto')->readOnly()->dehydrated()->columnSpan(['md' => 3]),
                    TextInput::make('unidad')->label('Unidad')->readOnly()->dehydrated()->columnSpan(['md' => 1]),
                    TextInput::make('stock_inicial')->label('Stock inicial')->numeric()->minValue(0)->required()->live()->readOnly(fn (Get $get): bool => $get('origen_inicial') === 'cierre_anterior')
                        ->disabled(fn (): bool => $this->soloLectura())->afterStateUpdated(fn (Get $get, Set $set) => $this->actualizarCalculos($get, $set))->columnSpan(['md' => 1]),
                    TextInput::make('producido_hoy')->label('Acumulado producido')->numeric()->readOnly()->dehydrated()->columnSpan(['md' => 1]),
                    TextInput::make('stock_esperado')->label('Stock esperado')->numeric()->readOnly()->dehydrated()->columnSpan(['md' => 1]),
                    TextInput::make('stock_final')->label('Stock final físico')->numeric()->minValue(0)->inputMode('decimal')->live()->required(fn (): bool => $this->intentoGuardar === 'enviado')
                        ->disabled(fn (): bool => $this->soloLectura())->afterStateUpdated(fn (Get $get, Set $set) => $this->actualizarCalculos($get, $set))->columnSpan(['md' => 1]),
                    TextInput::make('diferencia')->label('Diferencia')->numeric()->readOnly()->dehydrated()->columnSpan(['md' => 1]),
                    Textarea::make('observacion')->label('Motivo de diferencia')->rows(1)->maxLength(500)
                        ->required(fn (Get $get): bool => $this->intentoGuardar === 'enviado' && abs((float) ($get('diferencia') ?? 0)) > 0.0001)
                        ->disabled(fn (): bool => $this->soloLectura())->columnSpan(['md' => 12]),
                ]),
            ]),
        ])->statePath('data');
    }

    public function registrarTandaProductoAction(): Action
    {
        return Action::make('registrarTandaProducto')
            ->label('Registrar tanda')
            ->icon('heroicon-o-plus')
            ->visible(fn (): bool => $this->puedeRegistrar() && ! $this->soloLectura())
            ->modalHeading(fn (Action $action): string => 'Registrar tanda · '.$this->productoDeAccion($action)->nombre)
            ->modalWidth('5xl')
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitActionLabel('Registrar tanda')
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
                abort_unless($this->puedeRegistrar(), 403);
                $producto = ProduccionProducto::query()->where('activo', true)->find($data['producto_id'] ?? null);
                $cantidad = is_numeric($data['cantidad'] ?? null) ? (float) $data['cantidad'] : 0;
                $nota = trim((string) ($data['nota'] ?? '')) ?: null;
                if (! $producto) throw ValidationException::withMessages(['producto_id' => 'El producto no está disponible para registrar.']);
                if ($cantidad <= 0) throw ValidationException::withMessages(['cantidad' => 'Ingresa una cantidad mayor que cero.']);

                $this->guardarTanda($producto, $cantidad, $nota);
                Notification::make()->success()->title('Tanda registrada')->body(number_format($cantidad, 2).' '.$producto->unidad.' de '.$producto->nombre)->send();
                $this->cargarHoy();
            });
    }

    private function guardarTanda(ProduccionProducto $producto, float $cantidad, ?string $nota): void
    {
        DB::transaction(function () use ($producto, $cantidad, $nota): void {
            $cierre = ProduccionDiariaCierre::query()->whereDate('fecha', $this->fechaOperativa())->lockForUpdate()->first();
            if ($cierre?->estado === 'aprobado') throw ValidationException::withMessages(['data.tanda.producto_id' => 'El cierre de hoy ya está aprobado.']);
            if ($cierre?->estado === 'enviado') throw ValidationException::withMessages(['data.tanda.producto_id' => 'El cierre está enviado; no se pueden agregar tandas.']);
            $cierre ??= new ProduccionDiariaCierre(['fecha' => $this->fechaOperativa(), 'area' => 'FABRICA', 'estado' => 'borrador', 'creado_por' => auth()->id()]);
            $cierre->save();
            $tandaCreada = $cierre->tandas()->create([
                'producto_id' => $producto->id, 'item_id' => (string) $producto->id, 'item_tipo' => 'produccion', 'item_codigo' => $producto->codigo,
                'item_nombre' => $producto->nombre, 'unidad' => $producto->unidad, 'cantidad' => $cantidad, 'nota' => $nota, 'registrado_por' => auth()->id(),
            ]);
            $this->auditar($cierre, 'tanda_registrada', null, ['tanda' => ['id' => $tandaCreada->id, 'producto_id' => $producto->id, 'cantidad' => $cantidad, 'nota' => $nota]]);
        });
    }

    public function abrirCierreFisico(): void { $this->mostrarCierre = true; $this->cargarHoy(); }
    public function guardarBorrador(): void { $this->guardar('borrador'); }
    public function enviarCierre(): void { $this->guardar('enviado'); }

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
        $this->form->fill(['observacion' => $cierre?->observacion, 'items' => $items]);
    }

    /** @return array<int, array<string, mixed>> */
    private function itemsParaFormulario(string $fecha, ?ProduccionDiariaCierre $cierre): array
    {
        $items = collect($this->itemsDesdeCatalogo($fecha))->keyBy('producto_id');
        foreach ($cierre?->detalles ?? [] as $detalle) {
            if (! $detalle->producto_id || ! $items->has($detalle->producto_id)) continue;
            $producido = $this->totalProducido($fecha, $detalle->producto_id);
            $items->put($detalle->producto_id, ['producto_id' => $detalle->producto_id, 'item_codigo' => $detalle->item_codigo, 'item_nombre' => $detalle->item_nombre,
                'unidad' => $detalle->unidad, 'stock_inicial' => $detalle->stock_inicial, 'producido_hoy' => $producido,
                'stock_esperado' => round((float) $detalle->stock_inicial + $producido, 4), 'stock_final' => $detalle->stock_final,
                'diferencia' => $detalle->diferencia, 'observacion' => $detalle->observacion, 'origen_inicial' => 'registro_guardado']);
        }
        return $items->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function itemsDesdeCatalogo(string $fecha): array
    {
        return ProduccionProducto::query()->where('activo', true)->orderBy('nombre')->get()->map(function (ProduccionProducto $producto) use ($fecha): array {
            $inicial = $this->stockInicialAnterior($fecha, $producto->id);
            $producido = $this->totalProducido($fecha, $producto->id);
            return ['producto_id' => $producto->id, 'item_codigo' => $producto->codigo, 'item_nombre' => $producto->nombre, 'unidad' => $producto->unidad,
                'stock_inicial' => $inicial ?? 0, 'producido_hoy' => $producido, 'stock_esperado' => round(($inicial ?? 0) + $producido, 4),
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
        $this->productosParaRegistro = $catalogo->map(function (array $item, int $productoId) use ($tandasPorProducto): array {
            $tanda = $tandasPorProducto->get($productoId);

            return [
                'id' => $productoId,
                'codigo' => $item['item_codigo'] ?? '',
                'nombre' => $item['item_nombre'],
                'unidad' => $item['unidad'] ?? 'UNIDAD',
                'stock_inicial' => (float) ($item['stock_inicial'] ?? 0),
                'producido_hoy' => (float) ($item['producido_hoy'] ?? 0),
                'disponible' => (float) ($item['stock_esperado'] ?? 0),
                'tandas' => (int) ($tanda?->tandas ?? 0),
            ];
        })->sortBy('nombre')->values()->all();
        $this->tandasRecientes = ProduccionDiariaTanda::query()->with('registrador')->whereHas('cierre', fn ($q) => $q->whereDate('fecha', $fecha))->latest('created_at')->limit(8)->get()
            ->map(fn (ProduccionDiariaTanda $t): array => ['producto' => $t->item_nombre, 'cantidad' => (float) $t->cantidad, 'unidad' => $t->unidad ?: 'UNIDAD',
                'nota' => $t->nota, 'hora' => $t->created_at?->timezone('America/Lima')->format('H:i'), 'usuario' => $t->registrador?->name])->all();
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

    private function guardar(string $destino): void
    {
        abort_unless($this->puedeRegistrar(), 403);
        if ($this->soloLectura()) throw ValidationException::withMessages(['items' => 'El cierre aprobado no puede modificarse.']);
        $this->intentoGuardar = $destino; $state = $this->form->getState(); $this->intentoGuardar = 'borrador';
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
        $catalogo = ProduccionProducto::query()->where('activo', true)->get()->keyBy('id');
        return collect($items)->map(function (array $item) use ($fecha, $catalogo, $requiereFinal): array {
            $producto = $catalogo->get($item['producto_id'] ?? null);
            if (! $producto) throw ValidationException::withMessages(['items' => 'El producto no pertenece al catálogo activo de Producción.']);
            $inicialAnterior = $this->stockInicialAnterior($fecha, $producto->id);
            $inicial = $inicialAnterior ?? (float) ($item['stock_inicial'] ?? 0);
            $producido = $this->totalProducido($fecha, $producto->id);
            $final = filled($item['stock_final'] ?? null) ? (float) $item['stock_final'] : null;
            if ($inicial < 0 || ($final !== null && $final < 0)) throw ValidationException::withMessages(['items' => 'Las cantidades no pueden ser negativas.']);
            if ($requiereFinal && $final === null) throw ValidationException::withMessages(['items' => "{$producto->nombre}: registra el stock final físico."]);
            $esperado = round($inicial + $producido, 4); $diferencia = $final === null ? null : round($final - $esperado, 4);
            $observacion = trim((string) ($item['observacion'] ?? '')) ?: null;
            if ($requiereFinal && $diferencia !== null && abs($diferencia) > 0.0001 && $observacion === null) throw ValidationException::withMessages(['items' => "{$producto->nombre}: indica el motivo de la diferencia."]);
            return ['producto_id' => $producto->id, 'item_id' => (string) $producto->id, 'item_tipo' => 'produccion', 'item_codigo' => $producto->codigo,
                'item_nombre' => $producto->nombre, 'unidad' => $producto->unidad, 'stock_inicial' => $inicial, 'producido_hoy' => $producido,
                'stock_esperado' => $esperado, 'stock_final' => $final, 'diferencia' => $diferencia, 'observacion' => $observacion];
        })->values()->all();
    }

    private function totalProducido(string $fecha, int $productoId): float
    {
        return (float) ProduccionDiariaTanda::query()->whereHas('cierre', fn ($q) => $q->whereDate('fecha', $fecha))->where('producto_id', $productoId)->sum('cantidad');
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
        $esperado = round((float) ($get('stock_inicial') ?? 0) + (float) ($get('producido_hoy') ?? 0), 4); $final = $get('stock_final');
        $set('stock_esperado', $esperado); $set('diferencia', filled($final) ? round((float) $final - $esperado, 4) : null);
    }

    private function snapshot(ProduccionDiariaCierre $cierre): array
    {
        return ['fecha' => $cierre->fecha?->toDateString(), 'estado' => $cierre->estado, 'observacion' => $cierre->observacion,
            'detalles' => $cierre->detalles->map(fn (ProduccionDiariaDetalle $d): array => $d->only(['producto_id', 'stock_inicial', 'producido_hoy', 'stock_esperado', 'stock_final', 'diferencia', 'observacion']))->all()];
    }

    private function auditar(ProduccionDiariaCierre $cierre, string $accion, ?array $antes, array $despues): void
    {
        ProduccionDiariaAuditoria::create(['cierre_id' => $cierre->id, 'accion' => $accion, 'antes' => $antes, 'despues' => $despues, 'usuario_id' => auth()->id()]);
    }
}
