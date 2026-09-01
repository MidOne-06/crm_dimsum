<?php

namespace App\Filament\Pages\Stock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Services\SalidasStockGatewayClient;
use App\Services\SalidasStockHistoricoService;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class NuevaSalidaStock extends Page
{
    use ScopesLocalsToUser;

    /** Productos de uso frecuente disponibles como opciones rápidas. */
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

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-plus-circle';
    protected static ?string $navigationLabel = 'Nueva salida';
    protected static ?string $title = 'Nueva salida de stock';
    protected static string|\UnitEnum|null $navigationGroup = 'Stock Actual';
    protected static ?int $navigationSort = 14;
    protected static ?string $slug = 'salidas-stock/nueva';
    protected string $view = 'filament.pages.stock.nueva-salida-stock';

    public array $locals = [];
    public array $warehouses = [];
    public array $categories = [];
    public array $itemLookup = [];
    public ?array $data = [];
    public ?string $loadError = null;
    /** @var array{local: string, items: int}|null */
    public ?array $saveSuccess = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('salidas-stock.crear');
    }

    public function mount(): void
    {
        try {
            $this->locals = $this->scopeLocalsToUser($this->gateway()->locales());
            $this->categories = $this->gateway()->categorias();
            $localId = (string) ($this->locals[0]['id'] ?? '');
            $this->refreshWarehouses($localId);
            $this->form->fill([
                'local_id' => $localId,
                'almacen_id' => (string) ($this->warehouses[0]['id'] ?? ''),
                'categoria_id' => (string) ($this->categories[0]['id'] ?? ''),
                'fecha' => now()->toDateString(), 'razon' => '', 'catalog_items' => [], 'items' => [],
            ]);
        } catch (Throwable $exception) {
            $this->loadError = $this->friendlyError($exception);
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->compact()->schema([
                Grid::make(['default' => 1, 'md' => 2, 'xl' => 4])->schema([
                    Select::make('local_id')->label('Local')->native(false)->searchable()->required()
                        ->options(fn (): array => collect($this->locals)->pluck('name', 'id')->all())->live()
                        ->afterStateUpdated(function (?string $state, Set $set): void { $this->refreshWarehouses((string) $state); $set('almacen_id', $this->warehouses[0]['id'] ?? ''); $set('items', []); }),
                    Select::make('almacen_id')->label('Almacén')->native(false)->searchable()->required()
                        ->options(fn (): array => collect($this->warehouses)->pluck('name', 'id')->all()),
                    Select::make('categoria_id')->label('Categoría')->native(false)->searchable()->required()
                        ->options(fn (): array => collect($this->categories)->pluck('name', 'id')->all()),
                    DatePicker::make('fecha')->label('Fecha')->native(false)->locale('es')->displayFormat('d/m/Y')->required()->maxDate(now()),
                ]),
                Textarea::make('razon')->label('Razón')->required()->rows(1)->maxLength(500),
            ]),
            Section::make('Productos estándar')->compact()->schema([
                CheckboxList::make('catalog_items')->hiddenLabel()->options(fn (): array => $this->standardCatalogOptions())
                    ->columns(['default' => 1, 'sm' => 2, 'xl' => 3])->searchable()->searchPrompt('Buscar por nombre o código')->live()
                    ->afterStateUpdated(function (?array $state, Set $set): void {
                        $currentItems = collect($this->data['items'] ?? [])->keyBy('item_key');
                        $items = collect($state ?? [])->map(function (string $key) use ($currentItems): array {
                            $existing = $currentItems->get($key, []);

                            return [
                                'item_key' => $key,
                                'item_name' => $this->itemLabel($key),
                                'quantity' => $existing['quantity'] ?? 1,
                                'item' => $existing['item'] ?? $this->itemForKey($key),
                            ];
                        })->values()->all();

                        $set('items', $items);
                    }),
            ]),
            Section::make('Ítems')->compact()->schema([
                Repeater::make('items')->label('')->addable(false)->deletable(false)->defaultItems(0)->reorderable(false)
                    ->schema([
                        Hidden::make('item_key')->dehydrated(),
                        TextInput::make('item_name')->label('Ítem')->disabled()->dehydrated(false)->columnSpan(['default' => 1, 'md' => 3]),
                        TextInput::make('quantity')->label('Cantidad')->numeric()->minValue(0.001)->default(1)->required()->columnSpan(1),
                        Hidden::make('item')->dehydrated(),
                    ])->columns(['default' => 1, 'md' => 4])->itemLabel(fn (): string => ''),
            ]),
        ])->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('guardar')
            ->label('Registrar salida')
            ->icon('heroicon-o-arrow-up-tray')
            ->requiresConfirmation()
            ->modalHeading('Registrar salida de stock')
            ->modalDescription('Revise los productos y la razón antes de confirmar.')
            ->modalSubmitActionLabel('Sí, registrar salida')
            ->modalCancelActionLabel('Revisar datos')
            ->action(fn () => $this->guardar())];
    }

    public function refrescarDatosRestaurant(): void
    {
        try {
            $this->locals = $this->scopeLocalsToUser($this->gateway()->locales());
            $this->categories = $this->gateway()->categorias();
            $localId = (string) ($this->data['local_id'] ?? '');
            if ($localId !== '') $this->refreshWarehouses($localId, true);
            $this->loadError = null;
        } catch (Throwable $exception) { $this->loadError = $this->friendlyError($exception); }
    }

    public function itemSearchResults(string $search): array
    {
        $catalog = $this->standardCatalogOptions($search);
        $localId = (string) ($this->data['local_id'] ?? '');
        if (mb_strlen(trim($search)) < 3 || $localId === '') return $catalog;
        try {
            $items = $this->gateway()->items(trim($search), $localId);
            foreach ($items as $item) if (($key = $this->itemKey($item)) !== '') $this->itemLookup[$key] = $item;
            return array_replace($catalog, collect($items)->mapWithKeys(fn (array $item): array => [$this->itemKey($item) => $this->itemDisplay($item)])->all());
        } catch (Throwable $exception) { $this->loadError = $this->friendlyError($exception); return $catalog; }
    }

    public function guardar(): void
    {
        $this->saveSuccess = null;
        try {
            $state = $this->form->getState();
        } catch (Throwable) {
            $this->notificarError('Completa los campos obligatorios.');

            return;
        }
        $localId = (string) ($state['local_id'] ?? '');
        if (! $this->localAllowedForUser($localId)) {
            $this->notificarError('No tienes acceso al local seleccionado.');

            return;
        }
        $items = collect($state['items'] ?? [])->filter(fn (array $entry): bool => is_array($entry['item'] ?? null) && filled($entry['item']['item_id'] ?? null) && (float) ($entry['quantity'] ?? 0) > 0)->map(fn (array $entry): array => ['item' => $entry['item'], 'quantity' => (float) $entry['quantity']])->values()->all();
        if ($items === []) {
            $this->notificarError('Agrega al menos un ítem.');

            return;
        }
        try {
            $this->gateway()->guardar(['localId' => $localId, 'warehouseId' => (string) ($state['almacen_id'] ?? ''), 'categoryId' => (string) ($state['categoria_id'] ?? ''), 'date' => (string) ($state['fecha'] ?? ''), 'reason' => trim((string) ($state['razon'] ?? '')), 'items' => $items]);

            try {
                $support = app(SalidasStockHistoricoService::class)->iniciar((string) $state['fecha'], (string) $state['fecha']);
                app(SalidasStockHistoricoService::class)->sincronizar($support, $this->gateway());
            } catch (Throwable $exception) {
                Log::warning('[NuevaSalidaStock] La salida fue registrada, pero no se pudo actualizar el respaldo local: '.$exception->getMessage());
            }

            $this->saveSuccess = [
                'local' => (string) (collect($this->locals)->firstWhere('id', $localId)['name'] ?? 'el local seleccionado'),
                'items' => count($items),
            ];
            $this->itemLookup = [];
            $this->form->fill([
                'local_id' => $localId,
                'almacen_id' => (string) ($state['almacen_id'] ?? ''),
                'categoria_id' => (string) ($state['categoria_id'] ?? ''),
                'fecha' => (string) ($state['fecha'] ?? now()->toDateString()),
                'razon' => '',
                'catalog_items' => [],
                'items' => [],
            ]);
            $notification = Notification::make()
                ->title('Salida registrada correctamente')
                ->body('La pantalla está lista para registrar otra salida.')
                ->success();

            // El aviso visible es temporal, pero el mismo evento queda en el
            // centro de notificaciones del usuario que efectuó el registro.
            $notification->send();
            $this->guardarNotificacionEnHistorial($notification);
        } catch (Throwable $exception) {
            $this->notificarError($this->friendlyError($exception));
        }
    }

    public function continuarRegistro(): void
    {
        $this->saveSuccess = null;
    }

    private function notificarError(string $message): void
    {
        Notification::make()
            ->title('No se pudo registrar la salida')
            ->body($message)
            ->danger()
            ->send();
    }

    private function guardarNotificacionEnHistorial(Notification $notification): void
    {
        $user = auth()->user();

        if (! $user) {
            return;
        }

        // Filament encola sendToDatabase(); esta operación debe aparecer de
        // inmediato en el historial aunque no haya un worker disponible.
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => \Filament\Notifications\DatabaseNotification::class,
            'data' => $notification->getDatabaseMessage(),
        ]);
    }

    private function refreshWarehouses(string $localId, bool $preserve = false): void
    {
        $this->warehouses = $localId === '' ? [] : $this->gateway()->almacenes($localId);
        if (! $preserve) $this->data['almacen_id'] = $this->warehouses[0]['id'] ?? '';
    }
    public function standardCatalogOptions(string $search = ''): array
    {
        $search = mb_strtolower(trim($search));

        return collect(self::PRODUCTOS_ESTANDAR)
            ->filter(fn (string $description, string $code): bool => $search === '' || str_contains(mb_strtolower($code.' '.$description), $search))
            ->mapWithKeys(fn (string $description, string $code): array => ['catalog:'.$code => $description.' · '.$code])
            ->all();
    }

    public function itemLabels(array $values): array
    {
        return collect(array_filter($values))
            ->mapWithKeys(fn (string $key): array => [$key => $this->itemLabel($key)])
            ->all();
    }

    public function itemForKey(?string $key): array
    {
        if (! $key) return [];
        if (isset($this->itemLookup[$key])) return $this->itemLookup[$key];
        if (! str_starts_with($key, 'catalog:')) return [];

        $code = substr($key, strlen('catalog:'));
        $localId = (string) ($this->data['local_id'] ?? '');
        if ($code === '' || $localId === '') return [];

        try {
            $item = collect($this->gateway()->items($code, $localId))
                ->first(fn (array $candidate): bool => mb_strtoupper((string) ($candidate['item_codigo'] ?? $candidate['codigo'] ?? '')) === mb_strtoupper($code));

            if (! is_array($item)) return [];
            $this->itemLookup[$key] = $item;
            $this->itemLookup[$this->itemKey($item)] = $item;

            return $item;
        } catch (Throwable $exception) {
            $this->loadError = $this->friendlyError($exception);

            return [];
        }
    }
    private function itemKey(array $item): string { return (string) ($item['item_id'] ?? $item['id'] ?? '').'|'.(string) ($item['item_tipo'] ?? ''); }
    private function itemDisplay(array $item): string { return trim((string) ($item['item_codigo'] ?? $item['codigo'] ?? '')).' · '.trim((string) ($item['item_descripcion'] ?? $item['descripcion'] ?? '')); }
    private function itemLabel(string $key): string
    {
        if (str_starts_with($key, 'catalog:')) {
            $code = substr($key, strlen('catalog:'));
            if (isset(self::PRODUCTOS_ESTANDAR[$code])) return self::PRODUCTOS_ESTANDAR[$code].' · '.$code;
        }
        $item = $this->itemLookup[$key] ?? null;
        if (! is_array($item)) {
            foreach ($this->data['items'] ?? [] as $row) {
                if (($row['item_key'] ?? null) === $key && is_array($row['item'] ?? null)) { $item = $row['item']; break; }
            }
        }
        return is_array($item) ? ($this->itemDisplay($item) ?: 'Ítem') : 'Ítem';
    }
    private function gateway(): SalidasStockGatewayClient { return app(SalidasStockGatewayClient::class); }
    private function friendlyError(Throwable $exception): string { Log::warning('[NuevaSalidaStock] '.$exception->getMessage()); return str_contains($exception->getMessage(), 'Connection refused') ? 'No se pudo conectar con Restaurant.' : $exception->getMessage(); }
}
