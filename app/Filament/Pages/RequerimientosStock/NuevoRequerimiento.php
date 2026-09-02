<?php

namespace App\Filament\Pages\RequerimientosStock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Services\RequerimientoStockGatewayClient;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class NuevoRequerimiento extends Page
{
    use ScopesLocalsToUser;

    private const PLANTILLAS_POR_LOCAL = 50;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static ?string $navigationLabel = 'Nuevo requerimiento';
    protected static ?string $title = 'Nuevo requerimiento de stock';
    protected static string|\UnitEnum|null $navigationGroup = 'Requerimientos de Stock';
    protected static ?int $navigationSort = 10;
    protected static ?string $slug = 'requerimientos-stock/nuevo';
    protected string $view = 'filament.pages.requerimientos-stock.nuevo';

    public bool $gatewayUnavailable = false;
    public ?string $loadError = null;

    /** @var array<int, array{id: string, name: string}> */
    public array $availableLocals = [];

    /** @var array<int, array{id: string, name: string}> Locales válidos como destino de producción. */
    public array $destinationLocals = [];

    /** @var array<int, array{id: string, nombre: string}> */
    public array $almacenOptions = [];

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** @var array<string, array<string, mixed>> Registros devueltos por Restaurant para el Select buscable. */
    public array $itemLookup = [];

    public bool $isSaving = false;
    public ?string $saveError = null;
    public ?string $plantillaImportadaId = null;
    public ?string $plantillaImportadaNombre = null;

    /** @var array<int, array<string, mixed>> */
    public array $plantillasDisponibles = [];

    public string $plantillasLocalFilter = '';
    public ?string $plantillasError = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('requerimientos-stock.crear');
    }

    public function mount(): void
    {
        try {
            $restaurantLocals = $this->gateway()->locals();
            // El local asignado protege quién solicita (origen). Restaurant
            // permite dirigir el requerimiento a cualquier local de producción.
            $this->availableLocals = $this->scopeLocalsToUser($restaurantLocals);
            $this->destinationLocals = $restaurantLocals;
        } catch (Throwable $exception) {
            $this->gatewayUnavailable = true;
            $this->loadError = $this->friendlyError($exception);

            return;
        }

        $localId = (string) ($this->availableLocals[0]['id'] ?? '');
        $this->refreshAlmacenes($localId);

        $this->form->fill([
            'local_origen_id' => $localId,
            'almacen_origen_id' => (string) ($this->almacenOptions[0]['id'] ?? ''),
            'local_destino_id' => '',
            'encargado' => trim((string) (auth()->user()?->name ?? '')),
            'receptor' => '',
            'observacion' => '',
            'fecha' => now()->addDay()->setTime(9, 0)->format('Y-m-d H:i:s'),
            'items' => [],
        ]);

        $this->aplicarPlantillaImportada();
        $this->plantillasLocalFilter = (string) ($this->data['local_origen_id'] ?? '');
        $this->cargarPlantillasDisponibles();
    }

    public function updatedPlantillasLocalFilter(string $localId): void
    {
        if (! $this->localAllowedForUser($localId)) {
            $this->plantillasLocalFilter = (string) ($this->availableLocals[0]['id'] ?? '');
        }

        $this->cargarPlantillasDisponibles();
    }

    /** @return array<string, string> */
    public function plantillasLocalOptions(): array
    {
        return collect($this->availableLocals)
            ->mapWithKeys(fn (array $local): array => [(string) $local['id'] => (string) $local['name']])
            ->all();
    }

    public function puedeUsarPlantillas(): bool
    {
        return (bool) auth()->user()?->hasPermission('requerimientos-stock.plantillas.importar');
    }

    public function esUsuarioTerminal(): bool
    {
        return (bool) auth()->user()?->roles()->where('slug', 'terminal')->exists();
    }

    public function cargarPlantillasDisponibles(): void
    {
        $this->plantillasDisponibles = [];
        $this->plantillasError = null;

        if (! $this->puedeUsarPlantillas()) {
            return;
        }

        $localId = $this->plantillasLocalFilter;
        if ($localId === '' || ! $this->localAllowedForUser($localId)) {
            return;
        }

        try {
            $result = $this->gateway()->plantillas($localId, 1, self::PLANTILLAS_POR_LOCAL);
            $this->plantillasDisponibles = collect($result['rows'] ?? [])
                ->filter(fn (mixed $plantilla): bool => is_array($plantilla) && (string) ($plantilla['id'] ?? '') !== '')
                ->map(function (array $plantilla) use ($localId): array {
                    $plantilla['local_origen_id'] = (string) ($plantilla['local_origen_id'] ?? $localId);
                    $plantilla['items_count'] = count($plantilla['recetas'] ?? []) + count($plantilla['insumos'] ?? []) + count($plantilla['productos'] ?? []);

                    return $plantilla;
                })
                ->values()
                ->all();
        } catch (Throwable $exception) {
            Log::warning('[NuevoRequerimiento] No se pudieron cargar las plantillas disponibles.', ['exception' => $exception]);
            $this->plantillasError = 'No se pudieron cargar las plantillas de este local.';
        }
    }

    public function seleccionarPlantilla(string $templateId): void
    {
        if (! $this->puedeUsarPlantillas()) {
            Notification::make()->title('No tienes permiso para importar plantillas.')->danger()->send();

            return;
        }

        $plantilla = collect($this->plantillasDisponibles)
            ->first(fn (array $row): bool => (string) ($row['id'] ?? '') === $templateId);

        if (! is_array($plantilla) || ! $this->localAllowedForUser((string) ($plantilla['local_origen_id'] ?? ''))) {
            Notification::make()->title('La plantilla no está disponible para tu usuario.')->danger()->send();

            return;
        }

        try {
            $importada = $this->gateway()->importarPlantilla($templateId, true);
            $origen = (string) ($importada['localOrigenId'] ?? '');
            if ($origen === '' || ! $this->localAllowedForUser($origen)) {
                Notification::make()->title('La plantilla no está disponible para tu usuario.')->danger()->send();

                return;
            }

            $importada['id'] = (string) ($importada['id'] ?? $templateId);
            $importada['nombre'] = (string) ($plantilla['nombre'] ?? '');
            $this->aplicarPlantilla($importada);
            Notification::make()->title('Plantilla cargada')->body((string) ($plantilla['nombre'] ?? ''))->success()->send();
        } catch (Throwable $exception) {
            Log::warning('[NuevoRequerimiento] No se pudo importar una plantilla disponible.', ['template_id' => $templateId, 'exception' => $exception]);
            Notification::make()->title('No se pudo cargar la plantilla desde Restaurant.')->danger()->send();
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Datos del requerimiento')
                    ->compact()
                    ->collapsible()
                    ->collapsed(fn (): bool => $this->plantillaImportadaId !== null)
                    ->visible(fn (): bool => ! $this->esUsuarioTerminal() || $this->plantillaImportadaId !== null)
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 2, 'xl' => 4])
                            ->schema([
                                Select::make('local_origen_id')
                                    ->label('Local origen')
                                    ->native(false)
                                    ->searchable()
                                    ->options(fn (): array => collect($this->availableLocals)->pluck('name', 'id')->all())
                                    ->live()
                                    ->required()
                                    ->disabled(fn (): bool => $this->esUsuarioTerminal())
                                    ->dehydrated()
                                    ->afterStateUpdated(function (?string $state, Set $set): void {
                                        $this->refreshAlmacenes($state ?? '');
                                        $set('almacen_origen_id', $this->almacenOptions[0]['id'] ?? '');
                                    }),
                                Select::make('almacen_origen_id')
                                    ->label('Almacén')
                                    ->native(false)
                                    ->searchable()
                                    ->options(fn (): array => collect($this->almacenOptions)->pluck('nombre', 'id')->all())
                                    ->required()
                                    ->disabled(fn (): bool => $this->esUsuarioTerminal())
                                    ->dehydrated(),
                                Select::make('local_destino_id')
                                    ->label('Local destino')
                                    ->native(false)
                                    ->searchable()
                                    ->options(fn (): array => collect($this->destinationLocals)->pluck('name', 'id')->all())
                                    ->required()
                                    ->disabled(fn (): bool => $this->esUsuarioTerminal())
                                    ->dehydrated(),
                                DateTimePicker::make('fecha')
                                    ->label('Abastecimiento')
                                    ->native(false)
                                    ->seconds(false)
                                    ->minDate(now()->addDay()->startOfDay())
                                    ->required()
                                    ->disabled(fn (): bool => $this->esUsuarioTerminal())
                                    ->dehydrated(),
                            ]),
                    ]),
                Section::make('Datos adicionales')
                    ->compact()
                    ->collapsible()
                    ->collapsed(fn (): bool => $this->plantillaImportadaId !== null)
                    ->visible(fn (): bool => ! $this->esUsuarioTerminal() || $this->plantillaImportadaId !== null)
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 2, 'xl' => 4])
                            ->schema([
                                TextInput::make('encargado')->label('Encargado')->required()->maxLength(100)->disabled(fn (): bool => $this->esUsuarioTerminal())->dehydrated(),
                                TextInput::make('receptor')->label('Receptor')->maxLength(100)->disabled(fn (): bool => $this->esUsuarioTerminal())->dehydrated(),
                                Textarea::make('observacion')->label('Observación')->rows(1)->maxLength(500)->disabled(fn (): bool => $this->esUsuarioTerminal())->dehydrated()->columnSpan(['md' => 2]),
                            ]),
                    ]),
                Section::make('Ítems')
                    ->compact()
                    ->visible(fn (): bool => ! $this->esUsuarioTerminal() || $this->plantillaImportadaId !== null)
                    ->schema([
                        Repeater::make('items')
                            ->label('')
                            ->hiddenLabel()
                            ->addActionLabel('Agregar ítem')
                            ->addActionAlignment(Alignment::Start)
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->itemNumbers(false)
                            ->compact()
                            ->grid(['default' => 1, 'xl' => 2])
                            ->columns(['default' => 1, 'md' => 6])
                            ->addable(fn (): bool => $this->plantillaImportadaId === null)
                            ->deletable(fn (): bool => $this->plantillaImportadaId === null)
                            ->schema(fn (): array => $this->plantillaImportadaId !== null
                                ? [
                                    TextInput::make('producto_nombre')
                                        ->label('Producto')
                                        ->hiddenLabel()
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->columnSpan(['default' => 1, 'md' => 3]),
                                    TextInput::make('presentacion')
                                        ->label('Presentación')
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->placeholder('—')
                                        ->columnSpan(['default' => 1, 'md' => 2]),
                                    TextInput::make('cantidad')
                                        ->label('Cantidad')
                                        ->integer()
                                        // A template may intentionally contain products with
                                        // quantity zero. They are not sent to Restaurant; at
                                        // least one positive integer is still enforced on save.
                                        ->minValue(0)
                                        ->dehydrated()
                                        ->required()
                                        ->columnSpan(1),
                                    Hidden::make('item')->dehydrated(),
                                ]
                                : [
                                    Select::make('item_key')
                                        ->label('Producto')
                                        ->hiddenLabel()
                                        ->native(false)
                                        ->searchable()
                                        ->optionsLimit(10)
                                        ->getSearchResultsUsing(fn (string $search): array => $this->itemSearchResults($search))
                                        ->getOptionLabelsUsing(fn (array $values): array => $this->itemLabels($values))
                                        ->required()
                                        ->live()
                                        ->afterStateUpdated(function (?string $state, Set $set): void {
                                            $item = $this->itemForKey($state);
                                            $set('item', $item);
                                            $set('presentacion', $this->itemPresentacion($item));
                                        })
                                        ->columnSpan(['default' => 1, 'md' => 3]),
                                    TextInput::make('presentacion')
                                        ->label('Presentación')
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->placeholder('—')
                                        ->columnSpan(['default' => 1, 'md' => 2]),
                                    TextInput::make('cantidad')
                                        ->label('Cantidad')
                                        ->integer()
                                        ->minValue(1)
                                        ->default(1)
                                        ->required()
                                        ->columnSpan(1),
                                    Hidden::make('item')->dehydrated(),
                                ]),
                    ]),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('operaciones')
                ->label('Operaciones')
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->modalHeading('Operaciones del requerimiento')
                ->modalWidth('5xl')
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->modalSubmitActionLabel('Continuar')
                ->visible(fn (): bool => ! $this->esUsuarioTerminal())
                ->schema([
                    Grid::make(['default' => 1, 'md' => 2, 'xl' => 4])
                        ->schema([
                            Select::make('operacion')
                                ->label('Operación')
                                ->native()
                                ->options(fn (): array => $this->operationOptions())
                                ->default('guardar')
                                ->live()
                                ->required()
                                ->columnSpan(['xl' => 2]),
                            Grid::make(['default' => 1, 'sm' => 2])
                                ->schema([
                                    Toggle::make('mostrar_costos')
                                        ->label('Mostrar costos en Restaurant')
                                        ->default(false)
                                        ->visible(fn (): bool => (bool) auth()->user()?->hasPermission('requerimientos-stock.costos.ver')),
                                    Toggle::make('mostrar_precio')
                                        ->label('Mostrar precios en Restaurant')
                                        ->default(false)
                                        ->visible(fn (): bool => (bool) auth()->user()?->hasPermission('requerimientos-stock.precios.ver')),
                                ])
                                ->columnSpan(['xl' => 2]),
                            TextInput::make('nombre_plantilla')
                                ->label('Nombre de plantilla')
                                ->maxLength(100)
                                ->visible(fn (callable $get): bool => in_array((string) $get('operacion'), ['guardar_y_plantilla', 'solo_plantilla'], true))
                                ->required(fn (callable $get): bool => in_array((string) $get('operacion'), ['guardar_y_plantilla', 'solo_plantilla'], true))
                                ->columnSpanFull(),
                        ]),
                ])
                ->action(fn (array $data) => $this->ejecutarOperacion($data)),
            Action::make('guardar')
                ->label('Guardar requerimiento')
                ->icon('heroicon-o-check')
                ->visible(fn (): bool => ! $this->esUsuarioTerminal() || $this->plantillaImportadaId !== null)
                ->action(fn () => $this->guardar()),
        ];
    }

    /** @return array<string, string> */
    public function operationOptions(): array
    {
        if ($this->esUsuarioTerminal()) {
            return ['guardar' => 'Guardar requerimiento'];
        }

        $user = auth()->user();
        $options = [
            'guardar' => 'Guardar requerimiento',
            'validar' => 'Validar datos',
        ];

        if ($user?->hasPermission('requerimientos-stock.plantillas.crear')) {
            $options['guardar_y_plantilla'] = 'Guardar requerimiento y generar plantilla';
            $options['solo_plantilla'] = 'Guardar solo como plantilla';
        }
        if ($user?->hasPermission('requerimientos-stock.solicitud-compra')) {
            $options['solicitud_compra'] = 'Guardar como solicitud de compra';
        }
        if ($this->plantillaImportadaId !== null && $user?->hasPermission('requerimientos-stock.plantillas.actualizar')) {
            $options['actualizar_plantilla'] = 'Actualizar plantilla importada';
        }

        return $options;
    }

    /** @param array<string, mixed> $operation */
    public function ejecutarOperacion(array $operation): void
    {
        $type = (string) ($operation['operacion'] ?? 'guardar');
        if (! array_key_exists($type, $this->operationOptions())) {
            Notification::make()->title('No tienes permiso para esta operación.')->danger()->send();

            return;
        }

        if ($type === 'validar') {
            try {
                $state = $this->form->getState();
                if ($this->validatedItemsFromState($state) === []) {
                    throw new \RuntimeException('Sin ítems');
                }
                Notification::make()->title('Datos válidos')->body('El requerimiento está listo para guardarse.')->success()->send();
            } catch (Throwable) {
                Notification::make()->title('Revisa los campos obligatorios y los ítems.')->danger()->send();
            }

            return;
        }

        $this->guardar(
            comoSolicitudCompra: $type === 'solicitud_compra',
            soloPlantilla: $type === 'solo_plantilla',
            generarPlantilla: $type === 'guardar_y_plantilla',
            nombrePlantilla: trim((string) ($operation['nombre_plantilla'] ?? '')),
            mostrarCostos: (bool) ($operation['mostrar_costos'] ?? false) && (bool) auth()->user()?->hasPermission('requerimientos-stock.costos.ver'),
            mostrarPrecio: (bool) ($operation['mostrar_precio'] ?? false) && (bool) auth()->user()?->hasPermission('requerimientos-stock.precios.ver'),
            actualizarPlantilla: $type === 'actualizar_plantilla',
        );
    }

    /** Actualiza catálogos remotos sin reemplazar el formulario editado. */
    public function refrescarDatosRestaurant(): void
    {
        try {
            $restaurantLocals = $this->gateway()->locals();
            $locals = $this->scopeLocalsToUser($restaurantLocals);
            $localId = (string) ($this->data['local_origen_id'] ?? '');
            $validIds = collect($locals)->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();

            $this->availableLocals = $locals;
            $this->destinationLocals = $restaurantLocals;
            if ($localId !== '' && in_array($localId, $validIds, true)) {
                $this->refreshAlmacenes($localId, preserveSelected: true);
            }
            $this->loadError = null;
        } catch (Throwable $exception) {
            $this->loadError = $this->friendlyError($exception);
        }
    }

    protected function refreshAlmacenes(string $localId, bool $preserveSelected = false): void
    {
        if ($localId === '') {
            $this->almacenOptions = [];

            return;
        }

        try {
            $this->almacenOptions = $this->gateway()->almacenes($localId);
            if (! $preserveSelected) {
                $this->data['almacen_origen_id'] = (string) ($this->almacenOptions[0]['id'] ?? '');
            }
        } catch (Throwable $exception) {
            $this->almacenOptions = [];
            $this->loadError = $this->friendlyError($exception);
        }
    }

    /** @return array<string, string> */
    public function itemSearchResults(string $search): array
    {
        if (mb_strlen(trim($search)) < 3) {
            return [];
        }

        try {
            $items = $this->gateway()->searchItems(trim($search));
            foreach ($items as $item) {
                if (is_array($item) && ($key = $this->itemKey($item)) !== '') {
                    $this->itemLookup[$key] = $item;
                }
            }

            return collect($items)->mapWithKeys(fn (array $item): array => [$this->itemKey($item) => $this->itemDisplay($item)])->all();
        } catch (Throwable $exception) {
            $this->loadError = $this->friendlyError($exception);

            return [];
        }
    }

    /** @param array<int, string> $values @return array<string, string> */
    public function itemLabels(array $values): array
    {
        $keys = array_values(array_filter($values));
        if ($keys === []) {
            return [];
        }

        $labels = [];
        foreach ($this->data['items'] ?? [] as $entry) {
            $item = $entry['item'] ?? [];
            if (is_array($item) && ($key = $this->itemKey($item)) !== '' && in_array($key, $keys, true)) {
                $labels[$key] = $this->itemDisplay($item);
            }
        }

        return $labels;
    }

    /** @return array<string, mixed> */
    public function itemForKey(?string $key): array
    {
        if (! $key) {
            return [];
        }

        return $this->itemLookup[$key] ?? [];
    }

    public function guardar(
        bool $comoSolicitudCompra = false,
        bool $soloPlantilla = false,
        bool $generarPlantilla = false,
        string $nombrePlantilla = '',
        bool $mostrarCostos = false,
        bool $mostrarPrecio = false,
        bool $actualizarPlantilla = false,
    ): void
    {
        $this->saveError = null;

        $permission = $comoSolicitudCompra ? 'requerimientos-stock.solicitud-compra' : ($soloPlantilla || $generarPlantilla ? 'requerimientos-stock.plantillas.crear' : ($actualizarPlantilla ? 'requerimientos-stock.plantillas.actualizar' : 'requerimientos-stock.crear'));
        if (! auth()->user()?->hasPermission($permission)) {
            $this->saveError = 'No tienes permiso para registrar esta operación.';

            return;
        }

        try {
            $state = $this->form->getState();
        } catch (Throwable) {
            $this->saveError = 'Completa los campos obligatorios.';

            return;
        }

        $localOrigenId = (string) ($state['local_origen_id'] ?? '');
        $almacenOrigenId = (string) ($state['almacen_origen_id'] ?? '');
        $localDestinoId = (string) ($state['local_destino_id'] ?? '');

        if ($localOrigenId === '' || $almacenOrigenId === '' || $localDestinoId === '') {
            $this->saveError = 'Completa local origen, almacén y local destino.';

            return;
        }

        if (! $this->localAllowedForUser($localOrigenId) || ! $this->destinationAvailable($localDestinoId)) {
            $this->saveError = 'No tienes acceso al local seleccionado.';

            return;
        }

        try {
            $fechaSeleccionada = Carbon::parse((string) ($state['fecha'] ?? ''), config('app.timezone'));
        } catch (Throwable) {
            $this->saveError = 'Selecciona una fecha de abastecimiento válida.';

            return;
        }

        if ($fechaSeleccionada->lt(now()->startOfDay()->addDay())) {
            $this->saveError = 'El abastecimiento debe programarse desde mañana.';

            return;
        }

        $items = $this->validatedItemsFromState($state);

        if ($items === []) {
            $this->saveError = 'Agrega al menos un ítem.';

            return;
        }

        if ($this->esUsuarioTerminal() && ! $this->plantillaTerminalAutorizada($localOrigenId, $items)) {
            $this->saveError = 'La plantilla ya no está disponible para tu local. Vuelve a importarla.';

            return;
        }

        if (($soloPlantilla || $generarPlantilla) && $nombrePlantilla === '') {
            $this->saveError = 'Indica el nombre de la plantilla.';

            return;
        }
        if ($actualizarPlantilla && $this->plantillaImportadaId === null) {
            $this->saveError = 'Primero importa la plantilla que deseas actualizar.';

            return;
        }

        $this->isSaving = true;

        try {
            $this->gateway()->guardar(
                localOrigenId: $localOrigenId,
                almacenOrigenId: $almacenOrigenId,
                localDestinoId: $localDestinoId,
                encargado: trim((string) $state['encargado']),
                fecha: $fechaSeleccionada->format('Y-m-d H:i:s'),
                items: $items,
                receptor: trim((string) ($state['receptor'] ?? '')),
                observacion: trim((string) ($state['observacion'] ?? '')),
                esSolicitudCompra: $comoSolicitudCompra,
                esSoloPlantilla: $soloPlantilla,
                generarPlantilla: $generarPlantilla,
                nombrePlantilla: $nombrePlantilla,
                mostrarCostos: $mostrarCostos,
                mostrarPrecio: $mostrarPrecio,
                plantillaId: $actualizarPlantilla ? $this->plantillaImportadaId : null,
            );
        } catch (Throwable $exception) {
            $this->saveError = $this->friendlyError($exception);

            return;
        } finally {
            $this->isSaving = false;
        }

        Notification::make()->title($soloPlantilla ? 'Plantilla guardada' : ($comoSolicitudCompra ? 'Solicitud de compra guardada' : ($actualizarPlantilla ? 'Plantilla actualizada y requerimiento guardado' : 'Requerimiento guardado')))->success()->send();

        $this->data['items'] = [];
        $this->data['receptor'] = '';
        $this->data['observacion'] = '';
        $this->data['fecha'] = now()->addDay()->setTime(9, 0)->format('Y-m-d H:i:s');
        if ($this->esUsuarioTerminal()) {
            $this->plantillaImportadaId = null;
            $this->plantillaImportadaNombre = null;
        }
    }

    private function aplicarPlantillaImportada(): void
    {
        $plantilla = session()->pull('requerimientos-stock.plantilla-importada');
        if (! is_array($plantilla)) {
            return;
        }

        $this->aplicarPlantilla($plantilla);
    }

    /** @param array<string, mixed> $plantilla */
    private function aplicarPlantilla(array $plantilla): void
    {

        $origen = (string) ($plantilla['localOrigenId'] ?? '');
        $destino = (string) ($plantilla['localDestinoId'] ?? '');
        if ($origen === '' || $destino === '' || ! $this->localAllowedForUser($origen) || ! $this->destinationAvailable($destino)) {
            $this->loadError = 'La plantilla no está disponible para tu usuario.';

            return;
        }

        $this->refreshAlmacenes($origen);
        $this->plantillaImportadaId = (string) ($plantilla['id'] ?? '') ?: null;
        $this->plantillaImportadaNombre = (string) ($plantilla['nombre'] ?? '') ?: null;
        $this->data = array_replace($this->data ?? [], [
            'local_origen_id' => $origen,
            'almacen_origen_id' => (string) ($this->almacenOptions[0]['id'] ?? ''),
            'local_destino_id' => $destino,
            'encargado' => trim((string) ($plantilla['encargado'] ?? '')) ?: ($this->data['encargado'] ?? ''),
            'receptor' => (string) ($plantilla['receptor'] ?? ''),
            'observacion' => (string) ($plantilla['observacion'] ?? ''),
            'items' => collect($plantilla['items'] ?? [])
                ->filter(fn (mixed $entry): bool => is_array($entry) && is_array($entry['item'] ?? null) && ! empty($entry['item']['item_id']))
                ->map(fn (array $entry): array => [
                    'item_key' => $this->itemKey($entry['item']),
                    'item' => $entry['item'],
                    'producto_nombre' => $this->itemNombreCompleto($entry['item']),
                    'presentacion' => $this->itemPresentacion($entry['item']),
                    'cantidad' => (float) ($entry['cantidad'] ?? 1),
                ])
                ->values()
                ->all(),
        ]);

        $this->form->fill($this->data);
    }

    private function destinationAvailable(string $localId): bool
    {
        return in_array(
            $localId,
            collect($this->destinationLocals)->pluck('id')->map(fn (mixed $id): string => (string) $id)->all(),
            true,
        );
    }

    /**
     * Restaurant is the source of truth at save time. This avoids relying on
     * volatile Livewire/session state and confirms that every submitted item
     * still belongs to the selected template and assigned local.
     *
     * @param array<int, array{item: array<string, mixed>, cantidad: int}> $items
     */
    private function plantillaTerminalAutorizada(string $localOrigenId, array $items): bool
    {
        if ($this->plantillaImportadaId === null || ! $this->localAllowedForUser($localOrigenId)) {
            return false;
        }

        try {
            $plantilla = $this->gateway()->importarPlantilla($this->plantillaImportadaId, true);
            if (! hash_equals($localOrigenId, (string) ($plantilla['localOrigenId'] ?? ''))) {
                return false;
            }

            $itemKeys = collect($plantilla['items'] ?? [])
                ->map(fn (mixed $entry): string => is_array($entry) && is_array($entry['item'] ?? null) ? $this->itemKey($entry['item']) : '')
                ->filter()
                ->all();

            return $itemKeys !== [] && collect($items)
                ->every(fn (array $entry): bool => in_array($this->itemKey($entry['item']), $itemKeys, true));
        } catch (Throwable $exception) {
            Log::warning('[NuevoRequerimiento] No se pudo revalidar plantilla terminal.', [
                'template_id' => $this->plantillaImportadaId,
                'local_id' => $localOrigenId,
                'exception' => $exception,
            ]);

            return false;
        }
    }

    /** @param array<string, mixed> $state @return array<int, array{item: array<string, mixed>, cantidad: float}> */
    private function validatedItemsFromState(array $state): array
    {
        return collect($state['items'] ?? [])
            ->filter(fn (mixed $entry): bool => is_array($entry)
                && is_array($entry['item'] ?? null)
                && ! empty($entry['item']['item_id'])
                && $this->cantidadEntera($entry['cantidad'] ?? null) !== null)
            ->map(fn (array $entry): array => ['item' => $entry['item'], 'cantidad' => $this->cantidadEntera($entry['cantidad'])])
            ->values()
            ->all();
    }

    private function cantidadEntera(mixed $value): ?int
    {
        if (! is_numeric($value) || (float) $value < 1 || floor((float) $value) !== (float) $value) {
            return null;
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $item */
    private function itemKey(array $item): string
    {
        $id = (string) ($item['item_id'] ?? '');
        $type = (string) ($item['item_tipo'] ?? '');

        return $id === '' ? '' : $id.'|'.$type;
    }

    /** @param array<string, mixed> $item */
    private function itemDisplay(array $item): string
    {
        return trim((string) ($item['item_codigo'] ?? '')).' · '.trim((string) ($item['item_descripcion'] ?? ''));
    }

    /** @param array<string, mixed> $item */
    private function itemNombreCompleto(array $item): string
    {
        $name = trim((string) ($item['item_descripcion'] ?? $item['item_descripcion_original'] ?? ''));

        return preg_replace('/^\\([ID]\\)\\s*/i', '', $name) ?? $name;
    }

    /** @param array<string, mixed> $item */
    private function itemPresentacion(array $item): string
    {
        return trim((string) ($item['item_presentacion'] ?? $item['presentacion_nombre'] ?? $item['presentacion'] ?? ''));
    }

    private function itemLabel(string $key): string
    {
        foreach ($this->data['items'] ?? [] as $entry) {
            if (($entry['item_key'] ?? '') === $key && is_array($entry['item'] ?? null)) {
                return $this->itemDisplay($entry['item']);
            }
        }

        return 'Ítem';
    }

    /** @param array<string, mixed> $state */
    private function itemSummaryLabel(array $state): string
    {
        $label = $this->itemLabel((string) ($state['item_key'] ?? ''));
        $quantity = (float) ($state['cantidad'] ?? 0);

        return $quantity > 0 ? $label.' · '.rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.').' und.' : $label;
    }

    private function gateway(): RequerimientoStockGatewayClient
    {
        return app(RequerimientoStockGatewayClient::class);
    }

    private function friendlyError(Throwable $exception): string
    {
        Log::error('[RequerimientosStock] '.$exception->getMessage(), ['exception' => $exception]);

        if (str_contains($exception->getMessage(), 'cURL error 7') || str_contains($exception->getMessage(), 'Connection refused')) {
            return 'No se pudo conectar con Restaurant.';
        }

        return $exception->getMessage();
    }
}
