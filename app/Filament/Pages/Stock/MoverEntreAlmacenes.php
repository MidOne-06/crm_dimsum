<?php

namespace App\Filament\Pages\Stock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Services\MovimientosAlmacenesGatewayClient;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Illuminate\Support\Carbon;
use Throwable;

/** Alta operativa: todos los catálogos y validaciones provienen de Restaurant. */
class MoverEntreAlmacenes extends Page
{
    use ScopesLocalsToUser;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';
    protected static ?string $navigationLabel = 'Mover entre almacenes';
    protected static ?string $title = 'Mover entre almacenes';
    protected static string|\UnitEnum|null $navigationGroup = 'Movimientos entre almacenes';
    protected static ?int $navigationSort = 9;
    protected static ?string $slug = 'movimientos-almacenes/nuevo';
    protected string $view = 'filament.pages.stock.mover-entre-almacenes';
    protected Width|string|null $maxContentWidth = Width::Full;

    /** @var array<int, array<string, string>> */
    public array $locals = [];
    /** @var array<int, array<string, string>> */
    public array $warehouses = [];
    /** @var array<string, array<string, mixed>> */
    public array $itemLookup = [];
    /** @var array<string, mixed> */
    public array $data = [];
    /** @var array<int, array<string, mixed>> */
    public array $preview = [];
    public bool $stockRestricted = false;
    public ?string $loadError = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('movimientos-almacenes.crear');
    }

    public function mount(): void
    {
        try {
            $this->locals = $this->scopeLocalsToUser($this->gateway()->locales());
            $this->warehouses = $this->gateway()->almacenesTodos();
            $context = $this->gateway()->contextoFiltros();
            $localId = (string) ($context['local_id'] ?? '');
            if (! $this->localAllowed($localId)) $localId = (string) ($this->locals[0]['id'] ?? '');
            $origin = array_key_first($this->originOptionsFor($localId)) ?? '';
            $this->form->fill([
                'local_id' => $localId,
                'fecha' => now()->seconds(0)->format('Y-m-d H:i:s'),
                'encargado' => '',
                'receptor' => '',
                'almacen_origen' => $origin,
                'almacen_destino' => array_key_first($this->destinationOptionsFor($origin)) ?? '',
                'tipo_movimiento' => 'TRASLADO',
                'observacion' => '',
                'items' => [],
            ]);
        } catch (Throwable $exception) {
            report($exception);
            $this->loadError = 'Restaurant no respondió al cargar los datos para mover entre almacenes.';
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Datos del movimiento')->compact()->schema([
                Grid::make(['default' => 1, 'md' => 2, 'xl' => 15])->schema([
                    Select::make('local_id')->label('Local')->options(fn (): array => $this->localOptions())->native(false)->searchable()->required()->live()->columnSpan(['xl' => 2])
                        ->afterStateUpdated(function (?string $state, Set $set): void {
                            $origin = array_key_first($this->originOptionsFor((string) $state)) ?? '';
                            $set('almacen_origen', $origin);
                            $set('almacen_destino', array_key_first($this->destinationOptionsFor($origin)) ?? '');
                            $set('items', []);
                            $this->itemLookup = [];
                            $this->clearPreview();
                        }),
                    DateTimePicker::make('fecha')->label('Fecha de movimiento')->seconds(false)->native(false)->maxDate(now())->required()->columnSpan(['xl' => 2]),
                    TextInput::make('encargado')->label('Encargado del envío')->maxLength(160)->required()->live(onBlur: true)->columnSpan(['xl' => 2]),
                    TextInput::make('receptor')->label('Receptor')->maxLength(160)->columnSpan(['xl' => 2]),
                    Select::make('almacen_origen')->label('Almacén de origen')->options(fn (): array => $this->originOptions())->native(false)->searchable()->required()->live()->columnSpan(['xl' => 2])
                        ->afterStateUpdated(function (?string $state, Set $set): void {
                            $set('almacen_destino', array_key_first($this->destinationOptionsFor((string) $state)) ?? '');
                            $set('items', []);
                            $this->itemLookup = [];
                            $this->clearPreview();
                        }),
                    Select::make('almacen_destino')->label('Almacén de destino')->options(fn (): array => $this->destinationOptions())->native(false)->searchable()->required()->columnSpan(['xl' => 3]),
                    TextInput::make('tipo_movimiento')->label('Tipo de movimiento')->disabled()->dehydrated(false)->columnSpan(['xl' => 2]),
                ]),
            ]),
            Section::make('Lista de ítems a mover entre almacenes')->compact()->schema([
                Repeater::make('items')->label('')->hiddenLabel()->defaultItems(0)->reorderable(false)->itemNumbers(false)->compact()
                    ->addActionLabel('Agregar ítem')->addActionAlignment(Alignment::Start)->deletable(true)->columns(['default' => 1, 'md' => 6])
                    ->disabled(fn (): bool => ! $this->canAddItems())
                    ->schema([
                        Hidden::make('item_id')->dehydrated(), Hidden::make('item_tipo')->dehydrated(),
                        Hidden::make('presentacion_id')->dehydrated(), Hidden::make('unidadmedida_id')->dehydrated(), Hidden::make('presentacion_cantidad')->dehydrated(),
                        Select::make('item_key')->label('Buscar ítem')->native(false)->searchable()->required()
                            ->getSearchResultsUsing(fn (string $search): array => $this->itemOptions($search))
                            ->getOptionLabelUsing(fn (mixed $value): string => $this->itemLabel((string) $value))
                            ->live()->afterStateUpdated(fn (?string $state, Set $set) => $this->fillItem((string) $state, $set))->columnSpan(['md' => 2]),
                        TextInput::make('codigo')->label('Cód.')->disabled()->dehydrated(false),
                        TextInput::make('descripcion')->label('Ítem')->disabled()->dehydrated(false)->columnSpan(['md' => 2]),
                        TextInput::make('presentacion')->label('Presentación')->disabled()->dehydrated(false),
                        TextInput::make('cantidad')->label('Cant.')->numeric()->minValue(0.0001)->required()->live()->columnSpan(['md' => 2])
                            ->afterStateUpdated(fn (mixed $state, Set $set) => $set('cantidad_a_mover', $state)),
                        TextInput::make('cantidad_a_mover')->label('Cant. a mover')->numeric()->minValue(0.0001)->required()->columnSpan(['md' => 2]),
                        TextInput::make('unidad')->label('Unidad')->disabled()->dehydrated(false)->columnSpan(['md' => 2]),
                    ]),
            ]),
            Section::make('Anotaciones')->compact()->schema([
                Textarea::make('observacion')->label('')->hiddenLabel()->rows(2)->maxLength(1000)->placeholder('¿Por qué se está haciendo este movimiento? ¿Hay algún documento vinculado? Anótalo aquí'),
            ]),
        ])->statePath('data');
    }

    public function previsualizar(): void
    {
        try {
            $result = $this->gateway()->previsualizarNuevo($this->form->getState());
            $this->preview = is_array($result['movimientos'] ?? null) ? $result['movimientos'] : [];
            $this->stockRestricted = (bool) ($result['restringidoPorStockNegativo'] ?? false);
            Notification::make()->success()->title('Previsualización actualizada')->body('Restaurant validó los ítems y emuló el movimiento sin guardarlo.')->send();
        } catch (Throwable $exception) {
            report($exception);
            $this->clearPreview();
            Notification::make()->danger()->title('No se pudo previsualizar el movimiento')->body($exception->getMessage())->send();
        }
    }

    public function guardar(): void
    {
        try {
            $result = $this->gateway()->crear([...$this->form->getState(), 'confirmar' => true]);
            $id = (string) ($result['id'] ?? '');
            Notification::make()->success()->title('Movimiento registrado')->body($id !== '' ? "Restaurant registró el movimiento #{$id}." : 'Restaurant confirmó el registro.')->send();
            $this->mount();
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->danger()->title('No se pudo registrar el movimiento')->body($exception->getMessage())->send();
        }
    }

    public function updatedData(): void { $this->clearPreview(); }

    public function canAddItems(): bool
    {
        return $this->loadError === null && filled($this->data['encargado'] ?? null) && filled($this->data['local_id'] ?? null)
            && filled($this->data['almacen_origen'] ?? null) && filled($this->data['almacen_destino'] ?? null);
    }

    /** @return array<string, string> */
    public function localOptions(): array { return collect($this->locals)->mapWithKeys(fn (array $row): array => [(string) ($row['id'] ?? '') => (string) ($row['name'] ?? '')])->filter()->all(); }
    /** @return array<string, string> */
    public function originOptions(): array { return $this->originOptionsFor((string) ($this->data['local_id'] ?? '')); }
    /** @return array<string, string> */
    public function destinationOptions(): array { return $this->destinationOptionsFor((string) ($this->data['almacen_origen'] ?? '')); }
    /** @return array<string, string> */
    private function originOptionsFor(string $localId): array
    {
        return collect($this->warehouses)->filter(fn (array $row): bool => (string) ($row['localId'] ?? '') === $localId)
            ->mapWithKeys(fn (array $row): array => [(string) $row['id'] => (string) $row['name']])->all();
    }
    /** @return array<string, string> */
    private function destinationOptionsFor(string $originId): array
    {
        return collect($this->warehouses)->filter(fn (array $row): bool => (string) ($row['id'] ?? '') !== $originId)
            ->mapWithKeys(fn (array $row): array => [(string) $row['id'] => trim((string) ($row['localName'] ?? '').' · '.(string) ($row['name'] ?? ''))])->all();
    }
    /** @return array<string, string> */
    public function itemOptions(string $search): array
    {
        $localId = (string) ($this->data['local_id'] ?? '');
        if (! $this->canAddItems() || mb_strlen(trim($search)) < 2) return [];
        try {
            return collect($this->gateway()->items($search, $localId))->mapWithKeys(function (array $item): array {
                $key = implode(':', [(string) ($item['item_tipo'] ?? ''), (string) ($item['id'] ?? ''), (string) ($item['presentacion_id'] ?? '')]);
                $this->itemLookup[$key] = $item;
                return [$key => trim((string) ($item['codigo'] ?? '').' · '.(string) ($item['descripcion'] ?? '').' · '.(string) ($item['presentacion'] ?? ''))];
            })->all();
        } catch (Throwable) { return []; }
    }
    public function itemLabel(string $key): string { $item = $this->itemLookup[$key] ?? []; return trim((string) ($item['codigo'] ?? '').' · '.(string) ($item['descripcion'] ?? '').' · '.(string) ($item['presentacion'] ?? '')); }
    private function fillItem(string $key, Set $set): void
    {
        $item = $this->itemLookup[$key] ?? null;
        if (! is_array($item)) return;
        foreach (['id' => 'item_id', 'item_tipo' => 'item_tipo', 'presentacion_id' => 'presentacion_id', 'unidadmedida_id' => 'unidadmedida_id', 'presentacion_cantidad' => 'presentacion_cantidad', 'codigo' => 'codigo', 'descripcion' => 'descripcion', 'presentacion' => 'presentacion', 'unidad' => 'unidad'] as $from => $to) $set($to, $item[$from] ?? '');
        $set('cantidad', 1); $set('cantidad_a_mover', 1);
    }
    private function localAllowed(string $id): bool { return array_key_exists($id, $this->localOptions()); }
    private function clearPreview(): void { $this->preview = []; $this->stockRestricted = false; }
    private function gateway(): MovimientosAlmacenesGatewayClient { return app(MovimientosAlmacenesGatewayClient::class); }
}
