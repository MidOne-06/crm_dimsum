<?php

namespace App\Filament\Pages\Produccion;

use App\Models\DirectivaTransferenciaSugerencia;
use App\Models\KardexMovimiento;
use App\Models\ProduccionDiariaAuditoria;
use App\Models\ProduccionDiariaCierre;
use App\Models\ProduccionDiariaDetalle;
use Filament\Forms\Components\DatePicker;
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

/**
 * Cierre físico del área de producción. La Directiva de Transferencia define
 * el universo de productos; este módulo no crea movimientos de Kardex.
 */
class RegistroProduccionDiaria extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static ?string $navigationLabel = 'Registro de producción';
    protected static ?string $title = 'Registro diario de producción';
    protected static string|\UnitEnum|null $navigationGroup = 'Producción';
    protected static ?int $navigationSort = 1;
    protected static ?string $slug = 'produccion/registro';
    protected string $view = 'filament.pages.produccion.registro-produccion-diaria';

    /** @var array<string, mixed> */
    public array $data = [];
    public ?int $cierreId = null;
    public string $estado = 'nuevo';
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
        $this->cargarFecha(now()->toDateString());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Cierre de producción')->compact()->schema([
                Grid::make(['default' => 1, 'md' => 3])->schema([
                    DatePicker::make('fecha')->label('Fecha de producción')->native(false)->maxDate(now()->toDateString())->required()->live()
                        ->afterStateUpdated(fn (?string $state) => $state ? $this->cargarFecha($state) : null),
                    TextInput::make('area')->label('Área')->default('FABRICA')->readOnly()->dehydrated(),
                    TextInput::make('estado_actual')->label('Estado')->default(fn (): string => $this->etiquetaEstado())->readOnly()->dehydrated(false),
                    Textarea::make('observacion')->label('Observación general')->rows(2)->maxLength(1000)->columnSpanFull()->disabled(fn (): bool => $this->soloLectura()),
                ]),
            ]),
            Section::make('Productos de la DT')->description('La lista se consolida desde la Directiva de Transferencia de la fecha seleccionada.')->compact()->schema([
                Repeater::make('items')->label('')->hiddenLabel()->addable(false)->deletable(false)->reorderable(false)->itemNumbers(false)->compact()
                    ->columns(['default' => 1, 'md' => 12])->schema([
                        Hidden::make('item_id')->dehydrated(),
                        Hidden::make('item_tipo')->dehydrated(),
                        Hidden::make('origen_inicial')->dehydrated(),
                        TextInput::make('item_codigo')->label('Código')->readOnly()->dehydrated()->columnSpan(['md' => 1]),
                        TextInput::make('item_nombre')->label('Producto')->readOnly()->dehydrated()->columnSpan(['md' => 3]),
                        TextInput::make('unidad')->label('Unidad')->readOnly()->dehydrated()->columnSpan(['md' => 1]),
                        TextInput::make('stock_inicial')->label('Stock inicial')->numeric()->minValue(0)->required()->live()
                            ->readOnly(fn (Get $get): bool => $get('origen_inicial') === 'cierre_anterior')
                            ->disabled(fn (): bool => $this->soloLectura())
                            ->afterStateUpdated(fn (Get $get, Set $set) => $this->actualizarCalculos($get, $set))->columnSpan(['md' => 1]),
                        TextInput::make('producido_hoy')->label('Producido hoy')->numeric()->minValue(0)->required()->default(0)->live()
                            ->disabled(fn (): bool => $this->soloLectura())
                            ->afterStateUpdated(fn (Get $get, Set $set) => $this->actualizarCalculos($get, $set))->columnSpan(['md' => 1]),
                        TextInput::make('stock_esperado')->label('Stock esperado')->numeric()->readOnly()->dehydrated()->columnSpan(['md' => 1]),
                        TextInput::make('stock_final')->label('Stock final físico')->numeric()->minValue(0)->live()
                            ->required(fn (): bool => $this->intentoGuardar === 'enviado')
                            ->disabled(fn (): bool => $this->soloLectura())
                            ->afterStateUpdated(fn (Get $get, Set $set) => $this->actualizarCalculos($get, $set))->columnSpan(['md' => 1]),
                        TextInput::make('diferencia')->label('Diferencia')->numeric()->readOnly()->dehydrated()->columnSpan(['md' => 1]),
                        Textarea::make('observacion')->label('Motivo de diferencia')->rows(1)->maxLength(500)
                            ->required(fn (Get $get): bool => $this->intentoGuardar === 'enviado' && abs((float) ($get('diferencia') ?? 0)) > 0.0001)
                            ->disabled(fn (): bool => $this->soloLectura())->columnSpan(['md' => 12]),
                    ]),
            ]),
        ])->statePath('data');
    }

    public function guardarBorrador(): void
    {
        $this->guardar('borrador');
    }

    public function enviarCierre(): void
    {
        $this->guardar('enviado');
    }

    public function aprobar(): void
    {
        abort_unless((bool) auth()->user()?->hasPermission('produccion-diaria.aprobar'), 403);

        DB::transaction(function (): void {
            $cierre = ProduccionDiariaCierre::query()->lockForUpdate()->with('detalles')->findOrFail($this->cierreId);
            if ($cierre->estado !== 'enviado') {
                throw ValidationException::withMessages(['fecha' => 'Solo se puede aprobar un cierre enviado.']);
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
        $this->cargarFecha((string) ($this->data['fecha'] ?? now()->toDateString()));
    }

    public function puedeRegistrar(): bool
    {
        return (bool) auth()->user()?->hasPermission('produccion-diaria.registrar');
    }

    public function puedeAprobar(): bool
    {
        return (bool) auth()->user()?->hasPermission('produccion-diaria.aprobar');
    }

    public function soloLectura(): bool
    {
        return ! $this->puedeRegistrar() || $this->estado === 'aprobado';
    }

    public function etiquetaEstado(): string
    {
        return match ($this->estado) {
            'borrador' => 'Borrador', 'enviado' => 'Enviado', 'aprobado' => 'Aprobado', default => 'Nuevo',
        };
    }

    private function cargarFecha(string $fecha): void
    {
        $fecha = Carbon::parse($fecha)->toDateString();
        $cierre = ProduccionDiariaCierre::query()->with('detalles')->whereDate('fecha', $fecha)->first();
        $this->cierreId = $cierre?->id;
        $this->estado = $cierre?->estado ?? 'nuevo';
        $items = $cierre ? $cierre->detalles->map(fn (ProduccionDiariaDetalle $d): array => [
            'item_id' => $d->item_id, 'item_tipo' => $d->item_tipo, 'item_codigo' => $d->item_codigo,
            'item_nombre' => $d->item_nombre, 'unidad' => $d->unidad, 'stock_inicial' => $d->stock_inicial,
            'producido_hoy' => $d->producido_hoy, 'stock_esperado' => $d->stock_esperado,
            'stock_final' => $d->stock_final, 'diferencia' => $d->diferencia, 'observacion' => $d->observacion,
            'origen_inicial' => 'registro_guardado',
        ])->all() : $this->itemsDesdeDt($fecha);

        $this->form->fill(['fecha' => $fecha, 'area' => $cierre?->area ?? 'FABRICA', 'estado_actual' => $this->etiquetaEstado(), 'observacion' => $cierre?->observacion, 'items' => $items]);
    }

    /** @return array<int, array<string, mixed>> */
    private function itemsDesdeDt(string $fecha): array
    {
        return DirectivaTransferenciaSugerencia::query()->whereDate('fecha_despacho', $fecha)->where('cantidad_sugerida', '>', 0)
            ->orderBy('item_nombre')->get()->unique(fn ($row): string => $row->item_id.'|'.($row->item_tipo ?? ''))
            ->map(function (DirectivaTransferenciaSugerencia $row) use ($fecha): array {
                $inicial = $this->stockInicialAnterior($fecha, (string) $row->item_id, $row->item_tipo);
                $unidad = KardexMovimiento::query()->where('item_id', $row->item_id)->latest('fecha_hora')->value('unidad_medida') ?: 'UNIDAD';
                return [
                    'item_id' => (string) $row->item_id, 'item_tipo' => $row->item_tipo, 'item_codigo' => $row->item_codigo,
                    'item_nombre' => $row->item_nombre, 'unidad' => $unidad, 'stock_inicial' => $inicial ?? 0,
                    'producido_hoy' => 0, 'stock_esperado' => $inicial ?? 0, 'stock_final' => null,
                    'diferencia' => null, 'observacion' => null, 'origen_inicial' => $inicial === null ? 'apertura' : 'cierre_anterior',
                ];
            })->values()->all();
    }

    private function guardar(string $destino): void
    {
        abort_unless($this->puedeRegistrar(), 403);
        if ($this->soloLectura()) {
            throw ValidationException::withMessages(['fecha' => 'El cierre aprobado no puede modificarse.']);
        }

        $this->intentoGuardar = $destino;
        $state = $this->form->getState();
        $this->intentoGuardar = 'borrador';
        $fecha = Carbon::parse((string) $state['fecha'])->toDateString();
        $items = $this->normalizarItems($fecha, (array) ($state['items'] ?? []), $destino === 'enviado');

        DB::transaction(function () use ($fecha, $state, $items, $destino): void {
            $cierre = ProduccionDiariaCierre::query()->whereDate('fecha', $fecha)->lockForUpdate()->with('detalles')->first();
            if ($cierre?->estado === 'aprobado') {
                throw ValidationException::withMessages(['fecha' => 'El cierre ya fue aprobado y no puede sobrescribirse.']);
            }

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
        $this->cargarFecha($fecha);
    }

    /** @param array<int, array<string, mixed>> $items @return array<int, array<string, mixed>> */
    private function normalizarItems(string $fecha, array $items, bool $requiereFinal): array
    {
        if ($items === []) throw ValidationException::withMessages(['items' => 'No existen productos con cantidad sugerida en la DT de esta fecha.']);
        $dt = DirectivaTransferenciaSugerencia::query()->whereDate('fecha_despacho', $fecha)->where('cantidad_sugerida', '>', 0)->get()
            ->keyBy(fn ($row): string => $row->item_id.'|'.($row->item_tipo ?? ''));
        if ($dt->isEmpty()) throw ValidationException::withMessages(['fecha' => 'La DT de esta fecha no tiene productos para registrar.']);

        return collect($items)->map(function (array $item) use ($fecha, $dt, $requiereFinal): array {
            $key = (string) ($item['item_id'] ?? '').'|'.($item['item_tipo'] ?? '');
            $fuente = $dt->get($key);
            if (! $fuente) throw ValidationException::withMessages(['items' => 'Solo se pueden registrar productos que pertenezcan a la DT seleccionada.']);
            $inicialAnterior = $this->stockInicialAnterior($fecha, (string) $fuente->item_id, $fuente->item_tipo);
            $inicial = $inicialAnterior ?? (float) ($item['stock_inicial'] ?? 0);
            $producido = (float) ($item['producido_hoy'] ?? 0);
            $final = filled($item['stock_final'] ?? null) ? (float) $item['stock_final'] : null;
            if ($inicial < 0 || $producido < 0 || ($final !== null && $final < 0)) throw ValidationException::withMessages(['items' => 'Las cantidades no pueden ser negativas.']);
            if ($requiereFinal && $final === null) throw ValidationException::withMessages(['items' => "{$fuente->item_nombre}: registra el stock final físico."]);
            $esperado = round($inicial + $producido, 4);
            $diferencia = $final === null ? null : round($final - $esperado, 4);
            $observacion = trim((string) ($item['observacion'] ?? '')) ?: null;
            if ($requiereFinal && $diferencia !== null && abs($diferencia) > 0.0001 && $observacion === null) {
                throw ValidationException::withMessages(['items' => "{$fuente->item_nombre}: indica el motivo de la diferencia."]);
            }
            return ['item_id' => $fuente->item_id, 'item_tipo' => $fuente->item_tipo, 'item_codigo' => $fuente->item_codigo, 'item_nombre' => $fuente->item_nombre, 'unidad' => $item['unidad'] ?? 'UNIDAD', 'stock_inicial' => $inicial, 'producido_hoy' => $producido, 'stock_esperado' => $esperado, 'stock_final' => $final, 'diferencia' => $diferencia, 'observacion' => $observacion];
        })->values()->all();
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
        return ['fecha' => $cierre->fecha?->toDateString(), 'estado' => $cierre->estado, 'observacion' => $cierre->observacion, 'detalles' => $cierre->detalles->map(fn (ProduccionDiariaDetalle $d): array => $d->only(['item_id', 'item_tipo', 'stock_inicial', 'producido_hoy', 'stock_esperado', 'stock_final', 'diferencia', 'observacion']))->all()];
    }

    private function auditar(ProduccionDiariaCierre $cierre, string $accion, ?array $antes, array $despues): void
    {
        ProduccionDiariaAuditoria::create(['cierre_id' => $cierre->id, 'accion' => $accion, 'antes' => $antes, 'despues' => $despues, 'usuario_id' => auth()->id()]);
    }
}
