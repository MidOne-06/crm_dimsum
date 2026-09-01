<?php

namespace App\Filament\Pages\Stock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Services\StockFinalGatewayClient;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Consulta el stock por almacén y permite guardar un cuadre manual real
 * contra el ERP de Dim Sum (visible en Logística). Ver y guardar son
 * permisos separados: stock-final.view / stock-final.guardar.
 */
class StockFinal extends Page
{
    use ScopesLocalsToUser;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Carga Stock Final';

    protected static ?string $title = 'Stock por almacén (carga final)';

    protected static string|\UnitEnum|null $navigationGroup = 'Stock Actual';

    protected static ?int $navigationSort = 12;

    protected string $view = 'filament.pages.stock.final';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('stock-final.view');
    }

    public bool $gatewayUnavailable = false;

    public ?string $filtersError = null;

    /** @var array<int, array{id: string, name: string}> */
    public array $availableLocals = [];

    /** @var array<int, array{value: string, label: string}> */
    public array $tipoOptions = [];

    /** @var array<int, array{id: string, nombre: string}> */
    public array $almacenOptions = [];

    /** @var array<string, mixed> */
    public ?array $data = [];

    // Select buscables (categoría / plantilla) en un Schema aparte, con su propio
    // statePath -- así consiguen el mismo "escribe y filtra" que Local/Almacén/Tipo
    // sin mezclar su estado con el del formulario principal.
    /** @var array<string, mixed> */
    public ?array $filtrosData = ['postFilterCategoria' => '', 'plantillaSeleccionada' => ''];

    public bool $hasSearched = false;

    public int $itemsPage = 1;

    private const ITEMS_PER_PAGE = 10;

    public bool $isLoading = false;

    public ?string $resultError = null;

    /** @var array<int, array<string, mixed>> */
    public array $items = [];

    /**
     * Restaurant entrega un objeto muy grande por cada ítem. El objeto completo
     * queda temporalmente en caché para poder enviarlo intacto al guardar; la
     * propiedad Livewire contiene únicamente los campos que se muestran/editan.
     */
    public string $stockItemsCacheKey = '';

    public string $cuadreFecha = '';

    public string $cuadreMotivo = '';

    public bool $showConfirmGuardar = false;

    public bool $isSaving = false;

    public ?string $saveError = null;

    // Ítems tocados por "usar plantilla" en esta sesión de página. Se resaltan
    // aunque el valor precargado sea igual al stock del sistema (sin esto, un
    // ítem "aplicado" pero sin diferencia real es visualmente indistinguible
    // del resto -- la notificación dice "1 ítem" pero nada en la tabla lo marca).
    /** @var array<int, int> */
    public array $plantillaAplicadaIndexes = [];

    // Ítems marcados a mano (checkbox por fila) para incluirse al crear una
    // plantilla -- "Guardar como plantilla" solo manda estos, no la lista
    // completa cargada. Se limpia al hacer una nueva consulta.
    /** @var array<int, int> */
    public array $itemsSeleccionados = [];

    /** @var array<int, array{id: string, nombre: string, almacen_id: string, almacen_nombre: ?string, fecharegistro: string}> */
    public array $plantillas = [];

    public ?string $plantillaError = null;

    public bool $showGuardarPlantilla = false;

    public string $nombrePlantilla = '';

    public bool $isGuardandoPlantilla = false;

    public function mount(): void
    {
        $this->stockItemsCacheKey = (string) Str::uuid();

        try {
            $gateway = $this->gateway();
            $this->availableLocals = $this->scopeLocalsToUser($gateway->locals());
            $this->tipoOptions = $gateway->tipos();
        } catch (Throwable $exception) {
            $this->gatewayUnavailable = true;
            $this->filtersError = $this->friendlyError($exception);

            return;
        }

        $firstLocal = $this->availableLocals[0]['id'] ?? '';

        $this->form->fill([
            'local_id' => $firstLocal,
            'almacen_id' => '',
            'tipo' => '-1',
            'busqueda' => '',
            'registros' => '100',
        ]);

        $this->filtrosSchema->fill([
            'postFilterCategoria' => '',
            'plantillaSeleccionada' => '',
        ]);

        $this->refreshAlmacenes($firstLocal);
        $this->refreshPlantillas($firstLocal);
        // Formato compatible con <input type="datetime-local">. Restaurant Logística
        // exige fecha+hora completas (Y-m-d H:i:s) para el cuadre, no solo la fecha
        // -- por eso "La fecha asignada para el cuadre no es válida" si se manda un
        // valor sin hora.
        $this->cuadreFecha = now()->format('Y-m-d\TH:i');
    }

    public static function canGuardar(): bool
    {
        return (bool) auth()->user()?->hasPermission('stock-final.guardar');
    }

    public static function canUsarPlantilla(): bool
    {
        return (bool) auth()->user()?->hasPermission('stock-final.plantilla.usar');
    }

    public static function canGuardarPlantilla(): bool
    {
        return (bool) auth()->user()?->hasPermission('stock-final.plantilla.guardar');
    }

    // "Stock contado"/"Costo nuevo" y la fecha del cuadre se usan tanto al
    // guardar un cuadre real como al crear una plantilla -- se editan si el
    // usuario puede hacer cualquiera de las dos.
    public static function canEditarValores(): bool
    {
        return self::canGuardar() || self::canGuardarPlantilla();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['default' => 1, 'md' => 2, 'xl' => 5])
                    ->schema([
                        Select::make('local_id')
                            ->label('Local')
                            ->native(false)
                            ->searchable()
                            ->live()
                            ->options(fn (): array => collect($this->availableLocals)->pluck('name', 'id')->all())
                            ->afterStateUpdated(function (?string $state) {
                                $this->refreshAlmacenes($state ?? '');
                                $this->refreshPlantillas($state ?? '');
                            }),
                        Select::make('almacen_id')
                            ->label('Almacén')
                            ->native(false)
                            ->searchable()
                            ->options(fn (): array => collect($this->almacenOptions)->pluck('nombre', 'id')->all())
                            ->placeholder('Selecciona un almacén'),
                        Select::make('tipo')
                            ->label('Tipo')
                            ->native(false)
                            ->searchable()
                            ->options(fn (): array => collect($this->tipoOptions)->pluck('label', 'value')->all()),
                        TextInput::make('busqueda')
                            ->label('Buscar ítem')
                            ->placeholder('Código o descripción'),
                        Select::make('registros')
                            ->label('Registros')
                            ->native(false)
                            ->options([
                                '100' => '100',
                                '250' => '250',
                                '500' => '500',
                            ])
                            ->default('100'),
                    ]),
            ])
            ->statePath('data');
    }

    /** Select buscables de "Categoría" y "Plantilla", separados del form() principal para no mezclar su estado. */
    public function filtrosSchema(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['default' => 1, 'md' => 2])
                    ->schema([
                        Select::make('postFilterCategoria')
                            ->label('Categoría')
                            ->native(false)
                            ->searchable()
                            ->live()
                            ->placeholder('Todas las categorías')
                            ->options(fn (): array => array_combine($this->categoriaFilterOptions(), $this->categoriaFilterOptions())),
                        Select::make('plantillaSeleccionada')
                            ->label('Plantilla')
                            ->native(false)
                            ->searchable()
                            ->placeholder('Selecciona una plantilla')
                            ->options(fn (): array => collect($this->plantillas)
                                ->mapWithKeys(fn (array $plantilla): array => [
                                    $plantilla['id'] => $plantilla['nombre'].' ('.($plantilla['almacen_nombre'] ?? 'almacén desconocido').')',
                                ])
                                ->all()),
                    ]),
            ])
            ->statePath('filtrosData');
    }

    protected function refreshAlmacenes(string $localId): void
    {
        try {
            $this->almacenOptions = $localId !== '' ? $this->gateway()->almacenes($localId) : [];
        } catch (Throwable $exception) {
            $this->almacenOptions = [];
            $this->resultError = $this->friendlyError($exception);
        }

        $this->data['almacen_id'] = $this->almacenOptions[0]['id'] ?? '';
    }

    protected function refreshPlantillas(string $localId): void
    {
        try {
            $this->plantillas = $localId !== '' ? $this->gateway()->plantillas($localId) : [];
        } catch (Throwable $exception) {
            $this->plantillas = [];
        }

        $this->filtrosData['plantillaSeleccionada'] = '';
    }

    /**
     * Aplica una plantilla existente sobre los ítems ya cargados: sobreescribe
     * inventario_cantidad/costoNuevo de los ítems que coinciden por item_id.
     * No guarda nada en Logística todavía -- solo precarga valores para revisar
     * antes de "Guardar cuadre en Logística".
     */
    public function usarPlantilla(): void
    {
        $this->plantillaError = null;

        if (! self::canUsarPlantilla()) {
            $this->plantillaError = 'No tienes permiso para usar plantillas.';

            return;
        }

        $plantillaId = (string) ($this->filtrosData['plantillaSeleccionada'] ?? '');

        if ($plantillaId === '') {
            return;
        }

        if (! $this->hasSearched || empty($this->items)) {
            $this->plantillaError = 'Primero consulta el stock del almacén antes de usar una plantilla.';

            return;
        }

        $state = $this->form->getState();
        $almacenActual = (string) ($state['almacen_id'] ?? '');

        try {
            $plantilla = $this->gateway()->plantilla($plantillaId);
        } catch (Throwable $exception) {
            $this->plantillaError = $this->friendlyError($exception);

            return;
        }

        if ((string) ($plantilla['almacen_id'] ?? '') !== $almacenActual) {
            $this->plantillaError = 'Esta plantilla fue creada para otro almacén y no se puede aplicar aquí.';

            return;
        }

        $cantidadesPorItem = collect($plantilla['items'] ?? [])->keyBy('item_id');
        $this->plantillaAplicadaIndexes = [];

        foreach ($this->items as $index => $item) {
            $itemId = (string) ($item['item_id'] ?? '');
            $plantillaItem = $cantidadesPorItem->get($itemId);

            if (! $plantillaItem || ! isset($item['almacenes'][0])) {
                continue;
            }

            $this->items[$index]['almacenes'][0]['inventario_cantidad'] = $plantillaItem['cantidad'];
            $this->plantillaAplicadaIndexes[] = $index;
        }

        if (empty($this->plantillaAplicadaIndexes)) {
            $this->plantillaError = 'Ningún ítem de la plantilla coincide con los ítems cargados aquí.';

            return;
        }

        $aplicados = count($this->plantillaAplicadaIndexes);
        $this->soloDesdePlantilla = true;

        Notification::make()
            ->title('Plantilla aplicada')
            ->body($aplicados.' ítem(s) precargado(s) desde la plantilla. Mostrando solo esos ítems -- usa "Ver todos" para volver a la lista completa.')
            ->success()
            ->send();
    }

    public function abrirGuardarPlantilla(): void
    {
        $this->plantillaError = null;

        if (! self::canGuardarPlantilla()) {
            $this->plantillaError = 'No tienes permiso para crear plantillas.';

            return;
        }

        if (! $this->hasSearched || empty($this->items)) {
            $this->plantillaError = 'Primero consulta el stock del almacén antes de crear una plantilla.';

            return;
        }

        if (empty($this->itemsSeleccionados)) {
            $this->plantillaError = 'Marca al menos un ítem para crear la plantilla.';

            return;
        }

        $this->nombrePlantilla = '';
        $this->showGuardarPlantilla = true;
        $this->dispatch('open-modal', id: 'guardar-plantilla');
    }

    public function cancelarGuardarPlantilla(): void
    {
        $this->showGuardarPlantilla = false;
        $this->dispatch('close-modal', id: 'guardar-plantilla');
    }

    /**
     * Crea una plantilla en Logística (guardarComo=3): NO afecta el stock real,
     * no genera cuadre ni movimiento -- solo guarda la lista de ítems y sus
     * cantidades actuales como referencia reutilizable.
     */
    public function guardarComoPlantilla(): void
    {
        if (! self::canGuardarPlantilla()) {
            $this->plantillaError = 'No tienes permiso para crear plantillas.';
            $this->showGuardarPlantilla = false;

            return;
        }

        if (trim($this->nombrePlantilla) === '') {
            $this->plantillaError = 'Ingresa un nombre para la plantilla.';

            return;
        }

        $this->isGuardandoPlantilla = true;
        $this->plantillaError = null;

        $state = $this->form->getState();
        $localId = (string) ($state['local_id'] ?? '');

        if (! $this->localAllowedForUser($localId)) {
            $this->plantillaError = 'No tienes acceso a ese local.';
            $this->isGuardandoPlantilla = false;
            $this->showGuardarPlantilla = false;

            return;
        }

        $fechaEnviada = str_replace('T', ' ', $this->cuadreFecha).':00';

        try {
            $itemsParaPlantilla = collect($this->itemsSeleccionados)
                ->map(fn (int $index): array => $this->gatewayItem($index))
                ->values()
                ->all();

            $this->gateway()->guardarPlantilla($localId, $fechaEnviada, trim($this->nombrePlantilla), $itemsParaPlantilla, guardarComo: 3);

            Notification::make()
                ->title('Plantilla creada en Logística')
                ->body('"'.trim($this->nombrePlantilla).'" ya está disponible para usarse en futuros cuadres.')
                ->success()
                ->send();

            $this->refreshPlantillas($localId);
            $this->itemsSeleccionados = [];
        } catch (Throwable $exception) {
            $this->plantillaError = $this->friendlyError($exception);
        } finally {
            $this->isGuardandoPlantilla = false;
            $this->showGuardarPlantilla = false;
            $this->dispatch('close-modal', id: 'guardar-plantilla');
        }
    }

    public function search(): void
    {
        $this->isLoading = true;
        $this->resultError = null;

        $state = $this->form->getState();
        $localId = (string) ($state['local_id'] ?? '');

        // Defensa en profundidad: local_id es una propiedad pública de
        // Livewire (viaja en $state del form) -- un usuario restringido a
        // ciertos locales podría editar el payload wire:model y consultar
        // stock de un local fuera de su alcance. La lista de opciones del
        // Select ya viene filtrada, pero se revalida aquí el valor recibido.
        if ($localId !== '' && ! $this->localAllowedForUser($localId)) {
            $this->resultError = 'No tienes acceso a ese local.';
            $this->isLoading = false;

            return;
        }

        try {
            $rawItems = $this->gateway()->items([
                'local_id' => $state['local_id'] ?? '',
                'almacen_id' => $state['almacen_id'] ?? '',
                'categoria_id' => '-1',
                'tipo' => $state['tipo'] ?? '-1',
                'busqueda' => $state['busqueda'] ?? '',
                'registros' => $state['registros'] ?? '100',
            ]);
            Cache::put($this->stockItemsCacheKey, $rawItems, now()->addHours(2));
            $this->items = $this->compactItems($rawItems);
            $this->hasSearched = true;
            $this->itemsPage = 1;
            $this->filtrosData['postFilterCategoria'] = '';
            $this->plantillaAplicadaIndexes = [];
            $this->soloDesdePlantilla = false;
            $this->itemsSeleccionados = [];
        } catch (Throwable $exception) {
            $this->resultError = $this->friendlyError($exception);
        } finally {
            $this->isLoading = false;
        }
    }

    /** @param array<int, array<string, mixed>> $rawItems
     *  @return array<int, array<string, mixed>>
     */
    private function compactItems(array $rawItems): array
    {
        return array_map(function (array $item): array {
            $almacenes = array_map(fn (array $almacen): array => [
                'almacen_descripcion' => $almacen['almacen_descripcion'] ?? '',
                'almacen_id' => $almacen['almacen_id'] ?? '',
                'almacen_controlar' => $almacen['almacen_controlar'] ?? '',
                'cantidad2' => $almacen['cantidad2'] ?? 0,
                'inventario_cantidad' => $almacen['inventario_cantidad'] ?? 0,
                'costo' => $almacen['costo'] ?? 0,
                'costoNuevo' => $almacen['costoNuevo'] ?? 0,
            ], is_array($item['almacenes'] ?? null) ? $item['almacenes'] : []);

            return [
                'item_id' => $item['item_id'] ?? '',
                'item_codigo' => $item['item_codigo'] ?? '',
                'item_descripcion' => $item['item_descripcion'] ?? '',
                'categoria_descripcion' => $item['categoria_descripcion'] ?? '',
                'item_tipo' => $item['item_tipo'] ?? null,
                'item_sigla' => $item['item_sigla'] ?? '',
                'producto_um' => $item['producto_um'] ?? '',
                'almacenes' => $almacenes,
            ];
        }, $rawItems);
    }

    /** @return array<int, array<string, mixed>> */
    private function rawItems(): array
    {
        $rawItems = Cache::get($this->stockItemsCacheKey);

        if (! is_array($rawItems) || count($rawItems) !== count($this->items)) {
            throw new RuntimeException('La consulta venció. Vuelve a consultar el stock antes de guardar.');
        }

        return $rawItems;
    }

    /** @return array<string, mixed> */
    private function gatewayItem(int $index): array
    {
        $rawItem = $this->rawItems()[$index] ?? null;
        $visibleItem = $this->items[$index] ?? null;

        return $this->mergeGatewayItem($rawItem, $visibleItem);
    }

    /** @param mixed $rawItem
     *  @param mixed $visibleItem
     *  @return array<string, mixed>
     */
    private function mergeGatewayItem(mixed $rawItem, mixed $visibleItem): array
    {

        if (! is_array($rawItem) || ! is_array($visibleItem)) {
            throw new RuntimeException('La consulta venció. Vuelve a consultar el stock antes de guardar.');
        }

        $rawItem['almacenes'] = $visibleItem['almacenes'] ?? [];

        return $rawItem;
    }

    /** @return array<int, array<string, mixed>> */
    private function gatewayItems(): array
    {
        $rawItems = $this->rawItems();

        return array_map(
            fn (int $index): array => $this->mergeGatewayItem($rawItems[$index] ?? null, $this->items[$index] ?? null),
            array_keys($this->items),
        );
    }

    /** @return array<int, string> categorías presentes en la lista ya traída */
    public function categoriaFilterOptions(): array
    {
        $values = array_unique(array_filter(array_column($this->items, 'categoria_descripcion')));
        sort($values, SORT_STRING | SORT_FLAG_CASE);

        return array_values($values);
    }

    public function clearCategoriaFilter(): void
    {
        $this->filtrosData['postFilterCategoria'] = '';
        $this->itemsPage = 1;
    }

    public bool $soloDesdePlantilla = false;

    public function toggleSoloDesdePlantilla(): void
    {
        $this->soloDesdePlantilla = ! $this->soloDesdePlantilla;
        $this->itemsPage = 1;
    }

    /** @return array<int, int> índices de $items que pasan el filtro de categoría y/o "solo desde plantilla" */
    public function filteredItemIndexes(): array
    {
        $categoriaFiltro = (string) ($this->filtrosData['postFilterCategoria'] ?? '');

        $indexes = $categoriaFiltro === ''
            ? array_keys($this->items)
            : array_keys(array_filter(
                $this->items,
                fn (array $item): bool => ($item['categoria_descripcion'] ?? '') === $categoriaFiltro,
            ));

        if ($this->soloDesdePlantilla) {
            $indexes = array_values(array_intersect($indexes, $this->plantillaAplicadaIndexes));
        }

        return $indexes;
    }

    /** @return array<int, int> */
    public function paginatedItemIndexes(): array
    {
        return array_slice(
            $this->filteredItemIndexes(),
            ($this->itemsPage - 1) * self::ITEMS_PER_PAGE,
            self::ITEMS_PER_PAGE,
        );
    }

    public function itemsPageCount(): int
    {
        return max(1, (int) ceil(count($this->filteredItemIndexes()) / self::ITEMS_PER_PAGE));
    }

    public function previousItemsPage(): void
    {
        $this->itemsPage = max(1, $this->itemsPage - 1);
    }

    public function nextItemsPage(): void
    {
        $this->itemsPage = min($this->itemsPageCount(), $this->itemsPage + 1);
    }

    public function updatedFiltrosDataPostFilterCategoria(): void
    {
        $this->itemsPage = 1;
    }

    public function toggleItemSeleccionado(int $index): void
    {
        if (in_array($index, $this->itemsSeleccionados, true)) {
            $this->itemsSeleccionados = array_values(array_diff($this->itemsSeleccionados, [$index]));
        } else {
            $this->itemsSeleccionados[] = $index;
        }
    }

    /** Marca/desmarca todos los ítems que están visibles con el filtro de categoría actual. */
    public function toggleSeleccionarTodosFiltrados(): void
    {
        $filtrados = $this->filteredItemIndexes();

        if (empty(array_diff($filtrados, $this->itemsSeleccionados))) {
            $this->itemsSeleccionados = array_values(array_diff($this->itemsSeleccionados, $filtrados));
        } else {
            $this->itemsSeleccionados = array_values(array_unique(array_merge($this->itemsSeleccionados, $filtrados)));
        }
    }

    public function todosFiltradosSeleccionados(): bool
    {
        $filtrados = $this->filteredItemIndexes();

        return ! empty($filtrados) && empty(array_diff($filtrados, $this->itemsSeleccionados));
    }

    /** @return array<int, int> índices de $items cuyo valor editado difiere del original */
    public function changedIndexes(): array
    {
        $indexes = [];

        foreach ($this->items as $index => $item) {
            $almacen = $item['almacenes'][0] ?? null;
            if (! $almacen) {
                continue;
            }

            $cantidadCambio = (float) ($almacen['inventario_cantidad'] ?? 0) !== (float) ($almacen['cantidad2'] ?? 0);
            $costoCambio = (float) ($almacen['costoNuevo'] ?? 0) !== (float) ($almacen['costo'] ?? 0);

            if ($cantidadCambio || $costoCambio) {
                $indexes[] = $index;
            }
        }

        return $indexes;
    }

    public function openConfirmGuardar(): void
    {
        $this->saveError = null;

        if (! self::canGuardar()) {
            $this->saveError = 'No tienes permiso para guardar cuadres.';

            return;
        }

        if (empty($this->changedIndexes())) {
            $this->saveError = 'No hay ítems con cambios para guardar.';

            return;
        }

        $this->showConfirmGuardar = true;
        $this->dispatch('open-modal', id: 'confirm-guardar-cuadre');
    }

    public function cancelGuardar(): void
    {
        $this->showConfirmGuardar = false;
        $this->dispatch('close-modal', id: 'confirm-guardar-cuadre');
    }

    public function guardar(): void
    {
        if (! self::canGuardar()) {
            $this->saveError = 'No tienes permiso para guardar cuadres.';
            $this->showConfirmGuardar = false;

            return;
        }

        $this->isSaving = true;
        $this->saveError = null;

        $state = $this->form->getState();
        $localId = (string) ($state['local_id'] ?? '');

        if (! $this->localAllowedForUser($localId)) {
            $this->saveError = 'No tienes acceso a ese local.';
            $this->isSaving = false;
            $this->showConfirmGuardar = false;

            return;
        }

        // El input es datetime-local ("2026-08-20T14:23"); el gateway/ERP espera
        // "2026-08-20 14:23:00" (con segundos), igual que el frontend original de
        // cargar-stock-final (fromDateTimeLocal en su app.js).
        $fechaEnviada = str_replace('T', ' ', $this->cuadreFecha).':00';

        try {
            $result = $this->gateway()->guardar($localId, $fechaEnviada, $this->cuadreMotivo, $this->gatewayItems());

            Notification::make()
                ->title('Cuadre guardado en Logística')
                ->body(($result['itemsGuardados'] ?? 0).' ítem(s) guardado(s).')
                ->success()
                ->send();

            $this->cuadreMotivo = '';
            $this->search();
        } catch (Throwable $exception) {
            $this->saveError = $this->friendlyError($exception);
        } finally {
            $this->isSaving = false;
            $this->showConfirmGuardar = false;
            $this->dispatch('close-modal', id: 'confirm-guardar-cuadre');
        }
    }

    private function gateway(): StockFinalGatewayClient
    {
        return app(StockFinalGatewayClient::class);
    }

    private function friendlyError(Throwable $exception): string
    {
        Log::error('[Stock Final] '.$exception->getMessage(), ['exception' => $exception]);

        if (str_contains($exception->getMessage(), 'cURL error 7') || str_contains($exception->getMessage(), 'Connection refused')) {
            return 'No se pudo conectar con el servicio de Stock.';
        }

        return $exception->getMessage();
    }
}
