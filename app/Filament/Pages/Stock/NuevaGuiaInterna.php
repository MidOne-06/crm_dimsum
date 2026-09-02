<?php

namespace App\Filament\Pages\Stock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Services\GuiasInternasGatewayClient;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Throwable;

class NuevaGuiaInterna extends Page
{
    use ScopesLocalsToUser;

    /**
     * Catálogo operativo visible para despachos. La lista agiliza el registro,
     * pero cada selección se resuelve de nuevo contra Restaurant para guardar
     * su item_id, tipo, presentación y stock reales; nunca se envían IDs
     * inventados desde CRM.
     *
     * @var array<string, string>
     */
    private const PRODUCTOS_ESTANDAR = [
        'SM001' => 'SIU MAI TRADICIONAL',
        'SM002' => 'SIU MAI ESPECIAL',
        'SM003' => 'SIU MAI DE POLLO',
        'WK001' => 'WO TI KAO',
        'MP001' => 'MIN PAO DE POLLO',
        'MP002' => 'MIN PAO DE CERDO',
        'MP003' => 'MIN PAO DULCE',
        'MP004' => 'MIN PAO MIXTO',
        'ER001' => 'ENROLLADO PRIMAVERA',
        'AA003' => 'ALAS ASADAS',
        'AB001' => 'ALAS BROSTER',
        'KP001' => 'KAI PI',
        'WT001' => 'WANTAN',
        'SK001' => 'SIU KAO',
        'TP001' => 'TAYPAO',
        'CS001' => 'CHA SIU - 250 G',
        'CH001' => 'CHAUFA - 260 G',
        'SA001' => 'SALSA HOISIN - BOLSA 1 LT',
        'SA002' => 'SALSA TAMARINDO - BOLSA 1 LT',
        'SA003' => 'SALSA AJI - BOLSA 1 LT',
        'SA004' => 'SALSA SILLAO - BOLSA 0.5 LT',
        'SA005' => 'SALSA LIMON - BOLSA 0.5 LT',
        'IC001' => 'Inca Kola - 300 ML',
        'IC002' => 'Inca Kola - 600 ML',
        'CC001' => 'Coca Cola - 300 ML',
        'CC002' => 'Coca Cola - 600 ML',
        'CO002' => 'Chicha - 300 ML',
        'ASG002' => 'Agua - SAN MATEO 600 ML',
        'CO001' => 'CONCENTRADO DE CHICHA MORADA',
        'HA001' => 'HARINA SIN PREPARAR TIENDA',
    ];

    /** Códigos operativos que Restaurant no conserva como código del ítem. */
    private const BUSQUEDA_ALTERNATIVA_RESTAURANT = [
        'CO002' => 'CHICHA MORADA 300 ML',
    ];

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-plus';
    protected static ?string $navigationLabel = 'Nueva guía interna';
    protected static ?string $title = 'Nueva guía interna';
    protected static string|\UnitEnum|null $navigationGroup = 'Guías internas';
    protected static ?int $navigationSort = 12;
    protected static ?string $slug = 'guias-internas/nueva';
    protected string $view = 'filament.pages.stock.nueva-guia-interna';

    public array $locals = [];
    public array $warehouses = [];
    public array $motivos = [];
    public array $series = [];
    public array $itemLookup = [];
    public array $motorcyclistLookup = [];
    public array $carrierLookup = [];
    public array $clientLookup = [];
    public bool $isInternalTransport = false;
    public ?array $data = [];
    public ?string $loadError = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('guias-internas.crear');
    }

    public function mount(): void
    {
        try {
            $this->locals = $this->scopeLocalsToUser($this->gateway()->locales());
            $this->motivos = $this->gateway()->motivos();
            $origin = (string) ($this->locals[0]['id'] ?? '');
            $this->refreshWarehouses($origin);
            $recurrentes = $this->refreshRecurrentes($origin);
            $destination = $this->defaultDestino($origin);

            $this->form->fill([
                'origen_id' => $origin,
                'destino_id' => $destination,
                'almacen_id' => (string) ($recurrentes['almacenId'] ?? $this->warehouses[0]['id'] ?? ''),
                'motivo_id' => (string) ($this->motivos[0]['id'] ?? '6'),
                'transporte_interno' => false,
                'motorizado_id' => null,
                'transportista_id' => null,
                'cliente_id' => null,
                'placa' => '',
                'licencia' => '',
                'mtc' => '',
                'mostrar_costos' => false,
                'serie' => (string) ($recurrentes['serie'] ?? ''),
                'correlativo' => (string) ($recurrentes['correlativo'] ?? ''),
                'fecha_emision' => now(),
                'fecha_traslado' => now(),
                'direccion_destino_id' => $this->direccionIdForDestino($origin, $destination),
                'observacion' => '',
                'catalog_items' => [],
                'items' => [],
                'requerimiento_ids' => [],
            ]);

            $this->cargarRequerimientoParaDespacho();
        } catch (Throwable $exception) {
            $this->loadError = $this->friendlyError($exception);
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('requerimiento_ids')->default([]),
            Section::make()->compact()->schema([
                Grid::make(['default' => 1, 'md' => 2, 'xl' => 5])->schema([
                    Select::make('origen_id')->label('Local de origen')->native(false)->searchable()->required()
                        ->options(fn (): array => collect($this->locals)->pluck('name', 'id')->all())->live()
                        ->afterStateUpdated(function (?string $state, Set $set): void {
                            $this->refreshWarehouses((string) $state);
                            $recurrentes = $this->refreshRecurrentes((string) $state);
                            $destination = $this->defaultDestino((string) $state);
                            $set('almacen_id', filled($recurrentes['almacenId'] ?? null) ? $recurrentes['almacenId'] : ($this->warehouses[0]['id'] ?? ''));
                            $set('destino_id', $destination);
                            $set('direccion_destino_id', $this->direccionIdForDestino((string) $state, $destination));
                            $set('serie', $recurrentes['serie'] ?? '');
                            $set('correlativo', $recurrentes['correlativo'] ?? '');
                            $set('catalog_items', []);
                            $set('items', []);
                        }),
                    Select::make('destino_id')->label('Local de destino')->native(false)->searchable()->required()
                        ->options(fn (): array => $this->localDestinoOptions((string) ($this->data['origen_id'] ?? '')))->live()
                        ->afterStateUpdated(fn (?string $state, Set $set) => $set('direccion_destino_id', $this->direccionIdForDestino((string) ($this->data['origen_id'] ?? ''), (string) $state))),
                    Select::make('almacen_id')->label('Almacén')->native(false)->searchable()->required()
                        ->options(fn (): array => collect($this->warehouses)->pluck('name', 'id')->all()),
                    DateTimePicker::make('fecha_traslado')->label('Fecha de traslado')->native(false)->locale('es')->displayFormat('d/m/Y H:i')->seconds(false)->required(),
                ]),
                Grid::make(['default' => 1, 'md' => 2, 'xl' => 5])->schema([
                    Select::make('serie')->label('Serie')->native(false)->placeholder('Restaurant asignará automáticamente')->options(fn (): array => array_combine($this->series, $this->series) ?: [])
                        ->live()->afterStateUpdated(function (?string $state, Set $set): void {
                            if (blank($state)) return;
                            $set('correlativo', $this->gateway()->siguienteCorrelativo((string) $state)['correlativo'] ?? '');
                        }),
                    TextInput::make('correlativo')->label('Número')->numeric()->placeholder('Restaurant asignará automáticamente'),
                    DateTimePicker::make('fecha_emision')->label('Fecha de emisión')->native(false)->locale('es')->displayFormat('d/m/Y H:i')->seconds(false)->required(),
                    Select::make('direccion_destino_id')->label('Dirección')->native(false)->required()
                        ->options(fn (): array => $this->direccionDestinoOptions((string) ($this->data['origen_id'] ?? '')))->live()
                        ->afterStateUpdated(function (?string $state, Set $set): void {
                            if (filled($state) && $state !== '0') $set('destino_id', $state);
                        }),
                ]),
            ]),
            Section::make()->compact()->schema([
                Textarea::make('observacion')->label('Observación')->rows(1)->maxLength(500),
            ]),
            Section::make('Productos estándar')->compact()->schema([
                CheckboxList::make('catalog_items')
                    ->hiddenLabel()
                    ->options(fn (): array => $this->standardCatalogOptions())
                    ->columns(['default' => 1, 'sm' => 2, 'xl' => 3])
                    ->searchable()
                    ->searchPrompt('Buscar por nombre o código')
                    ->live()
                    ->afterStateUpdated(function (?array $state, Set $set): void {
                        $selected = array_values(array_filter($state ?? []));
                        $current = collect($this->data['items'] ?? []);

                        // Se conservan ítems añadidos manualmente o cargados
                        // desde un requerimiento; solo se reemplaza la parte
                        // del catálogo estándar elegida por el usuario.
                        $manual = $current
                            ->reject(fn (array $row): bool => str_starts_with((string) ($row['item_key'] ?? ''), 'catalog:'));
                        $catalogActual = $current->keyBy('item_key');

                        $catalog = collect($selected)->map(function (string $key) use ($catalogActual): array {
                            $existing = $catalogActual->get($key, []);
                            $item = is_array($existing['item'] ?? null) && filled($existing['item']['item_id'] ?? null)
                                ? $existing['item']
                                : $this->itemForKey($key);

                            return $this->itemRow($key, $item, (float) ($existing['cantidad'] ?? 1));
                        });

                        $set('items', $manual->concat($catalog)->values()->all());
                    }),
            ]),
            Section::make('Ítems')->compact()->schema([
                Repeater::make('items')->label('')->addActionLabel('Agregar ítem')->defaultItems(0)->reorderable(false)
                    ->schema([
                        Select::make('item_key')->label('Ítem')->native(false)->searchable()->required()
                            ->getSearchResultsUsing(fn (string $search): array => $this->itemSearchResults($search))
                            ->getOptionLabelUsing(fn (?string $value): ?string => $value ? $this->itemLabel($value) : null)
                            ->live()
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                $item = $state ? ($this->itemLookup[$state] ?? $this->itemForKey($state)) : [];
                                $set('item', $item);
                                $set('codigo', $item['item_codigo'] ?? $item['codigo'] ?? '');
                                $set('presentacion', $item['presentacion_nombre'] ?? $item['presentacion'] ?? '');
                                $set('categoria', $item['item_categoria'] ?? $item['categoria'] ?? '');
                                $set('stock', $item['item_stock'] ?? $item['stock'] ?? '');
                                $set('peso', $item['item_peso'] ?? $item['peso'] ?? 0);
                            })->columnSpan(['default' => 1, 'md' => 4]),
                        TextInput::make('cantidad')->label('Cantidad')->numeric()->minValue(0.001)->default(1)->required(),
                        TextInput::make('codigo')->label('Código')->disabled()->dehydrated(false),
                        TextInput::make('presentacion')->label('Presentación')->disabled()->dehydrated(false),
                        TextInput::make('categoria')->label('Categoría')->disabled()->dehydrated(false),
                        TextInput::make('stock')->label('Stock')->disabled()->dehydrated(false),
                        TextInput::make('peso')->label('Peso')->disabled()->dehydrated(false),
                        Hidden::make('item')->dehydrated(),
                    ])->columns(['default' => 1, 'md' => 5])->itemLabel(fn (): string => ''),
            ]),
        ])->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('opciones')
                ->label('Opciones de guía interna')
                ->icon('heroicon-o-adjustments-horizontal')
                ->color('gray')
                ->fillForm(function (): array {
                    $state = $this->guideOptionsState();
                    $this->isInternalTransport = (bool) ($state['transporte_interno'] ?? false);

                    return $state;
                })
                ->schema($this->guideOptionsSchema())
                ->modalHeading('Opciones de guía interna')
                ->modalWidth('5xl')
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->modalSubmitActionLabel('Guardar opciones')
                ->modalCancelActionLabel('Cerrar')
                ->action(function (array $data): void {
                    $this->isInternalTransport = (bool) ($data['transporte_interno'] ?? false);
                    $this->data = array_replace($this->data ?? [], $data);
                    $this->form->fill($this->data);
                }),
            Action::make('guardar')->label('Registrar guía interna')->icon('heroicon-o-document-check')
                ->requiresConfirmation()->modalHeading('Registrar guía interna')
                ->modalDescription('Se descontará el stock del almacén de origen.')
                ->modalWidth('lg')->stickyModalHeader()->stickyModalFooter()
                ->modalSubmitActionLabel('Sí, registrar guía')->modalCancelActionLabel('Revisar datos')
                ->action(fn () => $this->guardar()),
        ];
    }

    private function guideOptionsSchema(): array
    {
        return [
            Checkbox::make('transporte_interno')->label('Transporte interno')->live()
                ->afterStateUpdated(function (bool $state, Set $set): void {
                    $this->isInternalTransport = $state;
                    if ($state) {
                        $set('transportista_id', null);
                    } else {
                        $set('motorizado_id', null);
                        $set('licencia', '');
                    }
                }),
            Select::make('motorizado_id')->label('Motorizado')->native(false)->searchable()->visible(fn (): bool => $this->isInternalTransport)
                ->getSearchResultsUsing(fn (string $search): array => $this->motorcyclistSearchResults($search))
                ->getOptionLabelUsing(fn (?string $value): ?string => $value ? $this->motorcyclistLabel($value) : null)
                ->live()->afterStateUpdated(function (?string $state, Set $set): void {
                    $motorizado = $state ? ($this->motorcyclistLookup[$state] ?? []) : [];
                    $set('placa', (string) ($motorizado['placa'] ?? ''));
                    $set('licencia', (string) ($motorizado['licencia'] ?? ''));
                    $set('mtc', (string) ($motorizado['mtc'] ?? ''));
                })
                ->createOptionForm([
                    TextInput::make('nombres')->label('Nombres')->required()->minLength(3),
                    TextInput::make('apellidos')->label('Apellidos')->required()->minLength(3),
                    TextInput::make('dni')->label('DNI')->required()->numeric()->length(8),
                    TextInput::make('licencia')->label('Licencia'),
                    TextInput::make('direccion')->label('Dirección')->required(),
                    TextInput::make('mtc')->label('MTC'),
                    TextInput::make('telefono')->label('Teléfono'),
                ])
                ->createOptionModalHeading('Nuevo motorizado')
                ->createOptionAction(fn (Action $action): Action => $action
                    ->modalWidth('5xl')
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->modalSubmitActionLabel('Guardar')
                    ->modalCancelActionLabel('Cancelar'))
                ->createOptionUsing(function (array $data): string {
                    $result = $this->gateway()->guardarMotorizado($data);
                    $motorizado = $result['motorizado'] ?? [];
                    $id = (string) ($motorizado['id'] ?? $result['id'] ?? '');
                    if ($id === '') throw new \RuntimeException('Restaurant no devolvió el identificador del motorizado.');
                    $this->motorcyclistLookup[$id] = $motorizado;

                    return $id;
                }),
            Select::make('transportista_id')->label('Transportista')->native(false)->searchable()->visible(fn (): bool => ! $this->isInternalTransport)
                ->getSearchResultsUsing(fn (string $search): array => $this->carrierSearchResults($search))
                ->getOptionLabelUsing(fn (?string $value): ?string => $value ? $this->carrierLabel($value) : null)
                ->live()->afterStateUpdated(function (?string $state, Set $set): void {
                    $transportista = $state ? ($this->carrierLookup[$state] ?? []) : [];
                    if (filled($transportista['placa'] ?? null)) $set('placa', (string) $transportista['placa']);
                    $set('mtc', (string) ($transportista['mtc'] ?? ''));
                }),
            Grid::make(2)->schema([
                TextInput::make('placa')->label('Placa')->maxLength(12),
                TextInput::make('licencia')->label('Nro. de licencia')->disabled()->dehydrated()->visible(fn (): bool => $this->isInternalTransport),
            ]),
            Select::make('motivo_id')->label('Motivo del traslado')->native(false)->required()
                ->options(fn (): array => collect($this->motivos)->pluck('name', 'id')->all()),
            Select::make('cliente_id')->label('Cliente')->native(false)->searchable()
                ->getSearchResultsUsing(fn (string $search): array => $this->clientSearchResults($search))
                ->getOptionLabelUsing(fn (?string $value): ?string => $value ? $this->clientLabel($value) : null),
            Checkbox::make('mostrar_costos')->label('Mostrar costos'),
        ];
    }

    public function itemSearchResults(string $search): array
    {
        $origin = (string) ($this->data['origen_id'] ?? '');
        $catalog = $this->standardCatalogOptions($search);
        if ($origin === '' || mb_strlen(trim($search)) < 2) {
            return $catalog;
        }

        try {
            $items = $this->gateway()->items(trim($search), $origin);
            foreach ($items as $item) {
                $key = $this->itemKey($item);
                if ($key !== '') $this->itemLookup[$key] = $item;
            }

            return array_replace($catalog, collect($items)->mapWithKeys(fn (array $item): array => [$this->itemKey($item) => $this->itemDisplay($item)])->all());
        } catch (Throwable $exception) {
            $this->loadError = $this->friendlyError($exception);

            return $catalog;
        }
    }

    public function motorcyclistSearchResults(string $search): array
    {
        if (mb_strlen(trim($search)) < 2) return [];
        $rows = $this->gateway()->motorizados(trim($search));
        foreach ($rows as $row) $this->motorcyclistLookup[(string) $row['id']] = $row;

        return collect($rows)->mapWithKeys(fn (array $row): array => [(string) $row['id'] => trim($row['nombre'].' '.(filled($row['placa'] ?? null) ? '· Placa: '.$row['placa'] : ''))])->all();
    }

    public function carrierSearchResults(string $search): array
    {
        if (mb_strlen(trim($search)) < 2) return [];
        $rows = $this->gateway()->transportistas(trim($search));
        foreach ($rows as $row) $this->carrierLookup[(string) $row['id']] = $row;

        return collect($rows)->mapWithKeys(fn (array $row): array => [(string) $row['id'] => trim($row['nombre'].' '.(filled($row['ruc'] ?? null) ? '· RUC: '.$row['ruc'] : ''))])->all();
    }

    public function clientSearchResults(string $search): array
    {
        if (mb_strlen(trim($search)) < 2) return [];
        $rows = $this->gateway()->clientes(trim($search));
        foreach ($rows as $row) $this->clientLookup[(string) $row['id']] = $row;

        return collect($rows)->mapWithKeys(fn (array $row): array => [(string) $row['id'] => trim($row['nombre'].' '.(filled($row['documento'] ?? null) ? '· '.$row['documento'] : ''))])->all();
    }

    public function guardar(): void
    {
        try {
            $state = $this->form->getState();
        } catch (Throwable) {
            $this->notificarError('Completa los campos obligatorios.');
            return;
        }

        $origin = (string) ($state['origen_id'] ?? '');
        if (! $this->localAllowedForUser($origin)) {
            $this->notificarError('No tienes acceso al local de origen seleccionado.');
            return;
        }

        $items = collect($state['items'] ?? [])->filter(fn (array $entry): bool => is_array($entry['item'] ?? null) && filled($entry['item']['item_id'] ?? null) && (float) ($entry['cantidad'] ?? 0) > 0)
            ->map(fn (array $entry): array => ['item' => $entry['item'], 'quantity' => (float) $entry['cantidad']])->values()->all();
        if ($items === []) {
            $this->notificarError('Agrega al menos un ítem.');
            return;
        }

        try {
            $result = $this->gateway()->guardar([
                'originLocalId' => $origin,
                'destinationLocalId' => (string) ($state['destino_id'] ?? ''),
                'warehouseId' => (string) ($state['almacen_id'] ?? ''),
                'motivoId' => (string) ($state['motivo_id'] ?? ''),
                'internalTransport' => (bool) ($state['transporte_interno'] ?? false),
                'motorcyclistId' => $state['motorizado_id'] ?? null,
                'carrierId' => $state['transportista_id'] ?? null,
                'clientId' => $state['cliente_id'] ?? null,
                'placa' => trim((string) ($state['placa'] ?? '')),
                'licencia' => trim((string) ($state['licencia'] ?? '')),
                'mtc' => trim((string) ($state['mtc'] ?? '')),
                'showCosts' => (bool) ($state['mostrar_costos'] ?? false),
                'serie' => trim((string) ($state['serie'] ?? '')),
                'correlativo' => trim((string) ($state['correlativo'] ?? '')),
                'emissionDate' => $this->dateTimeValue($state['fecha_emision'] ?? null),
                'transferDate' => $this->dateTimeValue($state['fecha_traslado'] ?? null),
                'destinationAddressLocalId' => (string) ($state['direccion_destino_id'] ?? '0'),
                'observacion' => trim((string) ($state['observacion'] ?? '')),
                'requerimientoIds' => array_values(array_filter((array) ($state['requerimiento_ids'] ?? []), fn (mixed $id): bool => ctype_digit((string) $id))),
                'items' => $items,
            ]);

            Notification::make()->title('Guía interna registrada')->body('Restaurant confirmó el registro '.($result['id'] ?? '').'.')->success()->send();
            $this->form->fill([
                'origen_id' => $origin, 'destino_id' => (string) ($state['destino_id'] ?? ''),
                'almacen_id' => (string) ($state['almacen_id'] ?? ''), 'motivo_id' => (string) ($state['motivo_id'] ?? ''),
                'transporte_interno' => (bool) ($state['transporte_interno'] ?? false), 'motorizado_id' => null, 'transportista_id' => null,
                'cliente_id' => $state['cliente_id'] ?? null, 'placa' => '', 'licencia' => '', 'mtc' => '', 'mostrar_costos' => (bool) ($state['mostrar_costos'] ?? false),
                'serie' => (string) ($state['serie'] ?? ''), 'correlativo' => '',
                'fecha_emision' => $state['fecha_emision'] ?? now(), 'fecha_traslado' => $state['fecha_traslado'] ?? now(),
                'direccion_destino_id' => $this->direccionIdForDestino($origin, (string) ($state['destino_id'] ?? '')), 'observacion' => '', 'catalog_items' => [], 'items' => [], 'requerimiento_ids' => [],
            ]);
        } catch (Throwable $exception) {
            $this->notificarError($this->friendlyError($exception));
        }
    }

    private function gateway(): GuiasInternasGatewayClient { return app(GuiasInternasGatewayClient::class); }

    /** Carga el mismo preparado que Restaurant usa en el canje de requerimientos. */
    private function cargarRequerimientoParaDespacho(): void
    {
        $id = trim((string) request()->query('requerimiento', ''));
        if (! ctype_digit($id)) return;

        $importado = $this->gateway()->importarRequerimientos([$id]);
        $guia = (array) ($importado['guiaremision'] ?? []);
        $origin = (string) ($guia['local_id'] ?? '');
        $destination = (string) ($guia['localQueSolicito'] ?? $importado['localQueSolicito'] ?? '');

        if (! $this->localAllowedForUser($origin) || ! isset($this->localDestinoOptions($origin)[$destination])) {
            throw new \RuntimeException('El requerimiento no pertenece a un origen o destino permitido para tu usuario.');
        }

        $this->refreshWarehouses($origin);
        $recurrentes = $this->refreshRecurrentes($origin);
        $items = [];
        foreach ((array) ($importado['productos'] ?? []) as $item) {
            if (! is_array($item) || blank($item['item_id'] ?? null)) continue;
            $key = $this->itemKey($item);
            $this->itemLookup[$key] = $item;
            $items[] = [
                'item_key' => $key,
                'item' => $item,
                'cantidad' => (float) ($item['item_cantidad'] ?? 0),
                'codigo' => $item['item_codigo'] ?? '',
                'presentacion' => $item['presentacion_nombre'] ?? '',
                'categoria' => $item['item_categoria'] ?? '',
                'stock' => $item['item_stock'] ?? '',
                'peso' => $item['item_peso'] ?? 0,
            ];
        }
        if ($items === []) throw new \RuntimeException('Restaurant no devolvió ítems para el requerimiento seleccionado.');

        $this->form->fill([
            'origen_id' => $origin,
            'destino_id' => $destination,
            'almacen_id' => (string) (filled($recurrentes['almacenId'] ?? null) ? $recurrentes['almacenId'] : ($this->warehouses[0]['id'] ?? '')),
            'motivo_id' => (string) ($this->motivos[0]['id'] ?? '6'),
            'transporte_interno' => false,
            'motorizado_id' => null,
            'transportista_id' => null,
            'cliente_id' => null,
            'placa' => '', 'licencia' => '', 'mtc' => '', 'mostrar_costos' => false,
            'serie' => (string) ($recurrentes['serie'] ?? ''),
            'correlativo' => (string) ($recurrentes['correlativo'] ?? ''),
            'fecha_emision' => now(), 'fecha_traslado' => now(),
            'direccion_destino_id' => $this->direccionIdForDestino($origin, $destination),
            'observacion' => (string) ($guia['guiaremision_observacion'] ?? ''),
            'requerimiento_ids' => [$id],
            'catalog_items' => [],
            'items' => $items,
        ]);
        Notification::make()->title('Requerimiento #'.$id.' cargado')->body('Revisa las cantidades antes de registrar la guía.')->info()->send();
    }
    private function refreshWarehouses(string $localId): void { $this->warehouses = $localId === '' ? [] : $this->gateway()->almacenes($localId); }
    private function refreshRecurrentes(string $localId): array { $recurrentes = $localId === '' ? [] : $this->gateway()->recurrentes($localId); $this->series = array_values(array_unique(array_filter($recurrentes['series'] ?? []))); return $recurrentes; }
    public function standardCatalogOptions(string $search = ''): array
    {
        $search = mb_strtolower(trim($search));

        return collect(self::PRODUCTOS_ESTANDAR)
            ->filter(fn (string $name, string $code): bool => $search === '' || str_contains(mb_strtolower($code.' '.$name), $search))
            ->mapWithKeys(fn (string $name, string $code): array => ['catalog:'.$code => $name.' · '.$code])
            ->all();
    }
    private function itemForKey(string $key): array
    {
        if (isset($this->itemLookup[$key])) return $this->itemLookup[$key];
        if (! str_starts_with($key, 'catalog:')) return [];

        $code = substr($key, strlen('catalog:'));
        $origin = (string) ($this->data['origen_id'] ?? '');
        if ($code === '' || $origin === '') return [];

        try {
            $item = collect($this->gateway()->items($code, $origin))->first(
                fn (array $candidate): bool => mb_strtoupper((string) ($candidate['item_codigo'] ?? $candidate['codigo'] ?? '')) === mb_strtoupper($code)
            );

            // CO002 es el código operativo solicitado, pero Restaurant lo
            // registra como "CHICHA MORADA 300 ML" con código "-". La
            // resolución por descripción conserva el ID oficial y evita que
            // el operador tenga que conocer esta particularidad del ERP.
            if (! is_array($item) && isset(self::BUSQUEDA_ALTERNATIVA_RESTAURANT[$code])) {
                $descripcion = self::BUSQUEDA_ALTERNATIVA_RESTAURANT[$code];
                $item = collect($this->gateway()->items($descripcion, $origin))->first(
                    fn (array $candidate): bool => mb_strtoupper(trim((string) ($candidate['item_descripcion'] ?? $candidate['descripcion'] ?? ''))) === $descripcion
                );
            }
            if (! is_array($item)) {
                $this->loadError = 'Restaurant no encontró el producto '.$code.' para el local de origen.';

                return [];
            }

            $this->itemLookup[$key] = $item;
            $this->itemLookup[$this->itemKey($item)] = $item;

            return $item;
        } catch (Throwable $exception) {
            $this->loadError = $this->friendlyError($exception);

            return [];
        }
    }
    private function itemRow(string $key, array $item, float $cantidad = 1): array
    {
        return [
            'item_key' => $key,
            'item' => $item,
            'cantidad' => $cantidad > 0 ? $cantidad : 1,
            'codigo' => $item['item_codigo'] ?? $item['codigo'] ?? substr($key, strlen('catalog:')),
            'presentacion' => $item['presentacion_nombre'] ?? $item['presentacion'] ?? '',
            'categoria' => $item['item_categoria'] ?? $item['categoria'] ?? '',
            'stock' => $item['item_stock'] ?? $item['stock'] ?? '',
            'peso' => $item['item_peso'] ?? $item['peso'] ?? 0,
        ];
    }
    private function itemKey(array $item): string { return (string) ($item['item_id'] ?? $item['id'] ?? '').'|'.(string) ($item['item_tipo'] ?? ''); }
    private function itemDisplay(array $item): string { return trim((string) ($item['item_codigo'] ?? $item['codigo'] ?? '')).' · '.trim((string) ($item['item_descripcion'] ?? $item['descripcion'] ?? '')); }
    private function itemLabel(string $key): string
    {
        if (str_starts_with($key, 'catalog:')) {
            $code = substr($key, strlen('catalog:'));

            return isset(self::PRODUCTOS_ESTANDAR[$code]) ? self::PRODUCTOS_ESTANDAR[$code].' · '.$code : $code;
        }

        return $this->itemDisplay($this->itemLookup[$key] ?? []);
    }
    private function motorcyclistLabel(string $id): string { return (string) ($this->motorcyclistLookup[$id]['nombre'] ?? 'Motorizado seleccionado'); }
    private function carrierLabel(string $id): string { return (string) ($this->carrierLookup[$id]['nombre'] ?? 'Transportista seleccionado'); }
    private function clientLabel(string $id): string { return (string) ($this->clientLookup[$id]['nombre'] ?? 'Cliente seleccionado'); }
    private function guideOptionsState(): array { return collect($this->data ?? [])->only(['transporte_interno', 'motorizado_id', 'transportista_id', 'cliente_id', 'placa', 'licencia', 'mtc', 'motivo_id', 'mostrar_costos'])->all(); }
    private function dateTimeValue(mixed $value): string { return filled($value) ? \Illuminate\Support\Carbon::parse($value)->format('Y-m-d H:i:s') : ''; }
    private function localDestinoOptions(string $origin): array
    {
        return collect($this->locals)->reject(fn (array $local): bool => (string) $local['id'] === $origin)->pluck('name', 'id')->all();
    }
    private function defaultDestino(string $origin): string { return (string) (array_key_first($this->localDestinoOptions($origin)) ?? ''); }
    private function direccionDestinoOptions(string $origin): array { return ['0' => 'Sin dirección de destino'] + collect($this->locals)->reject(fn (array $local): bool => (string) $local['id'] === $origin || blank($local['direccion'] ?? null))->mapWithKeys(fn (array $local): array => [(string) $local['id'] => (string) $local['direccion']])->all(); }
    private function direccionIdForDestino(string $origin, string $destination): string { return isset($this->direccionDestinoOptions($origin)[$destination]) ? $destination : '0'; }
    private function friendlyError(Throwable $exception): string { return 'No se pudo completar la operación. Intenta nuevamente.'; }
    private function notificarError(string $message): void { Notification::make()->title('No se pudo registrar la guía')->body($message)->danger()->send(); }
}
