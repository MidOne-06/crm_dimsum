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
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class NuevoRequerimiento extends Page
{
    use ScopesLocalsToUser;

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

    /** @var array<int, array{id: string, nombre: string}> */
    public array $almacenOptions = [];

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** @var array<string, array<string, mixed>> Registros devueltos por Restaurant para el Select buscable. */
    public array $itemLookup = [];

    public bool $isSaving = false;
    public ?string $saveError = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('requerimientos-stock.crear');
    }

    public function mount(): void
    {
        try {
            $this->availableLocals = $this->scopeLocalsToUser($this->gateway()->locals());
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
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Datos del requerimiento')
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 2, 'xl' => 3])
                            ->schema([
                                Select::make('local_origen_id')
                                    ->label('Local origen')
                                    ->native(false)
                                    ->searchable()
                                    ->options(fn (): array => collect($this->availableLocals)->pluck('name', 'id')->all())
                                    ->live()
                                    ->required()
                                    ->afterStateUpdated(function (?string $state, Set $set): void {
                                        $this->refreshAlmacenes($state ?? '');
                                        $set('almacen_origen_id', $this->almacenOptions[0]['id'] ?? '');
                                    }),
                                Select::make('almacen_origen_id')
                                    ->label('Almacén')
                                    ->native(false)
                                    ->searchable()
                                    ->options(fn (): array => collect($this->almacenOptions)->pluck('nombre', 'id')->all())
                                    ->required(),
                                Select::make('local_destino_id')
                                    ->label('Local destino')
                                    ->native(false)
                                    ->searchable()
                                    ->options(fn (): array => collect($this->availableLocals)->pluck('name', 'id')->all())
                                    ->required(),
                                TextInput::make('encargado')->label('Encargado')->required()->maxLength(100),
                                TextInput::make('receptor')->label('Receptor')->maxLength(100),
                                DateTimePicker::make('fecha')
                                    ->label('Abastecimiento')
                                    ->native(false)
                                    ->seconds(false)
                                    ->minDate(now()->addDay()->startOfDay())
                                    ->required(),
                            ]),
                        Textarea::make('observacion')->label('Observación')->rows(2)->maxLength(500),
                    ]),
                Section::make('Ítems')
                    ->schema([
                        Repeater::make('items')
                            ->label('')
                            ->addActionLabel('Agregar ítem')
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->schema([
                                Select::make('item_key')
                                    ->label('Ítem')
                                    ->native(false)
                                    ->searchable()
                                    ->optionsLimit(10)
                                    ->getSearchResultsUsing(fn (string $search): array => $this->itemSearchResults($search))
                                    ->getOptionLabelsUsing(fn (array $values): array => $this->itemLabels($values))
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function (?string $state, Set $set): void {
                                        $set('item', $this->itemForKey($state));
                                    })
                                    ->columnSpan(['default' => 1, 'xl' => 2]),
                                TextInput::make('cantidad')
                                    ->label('Cantidad')
                                    ->numeric()
                                    ->minValue(0.01)
                                    ->default(1)
                                    ->required(),
                                Hidden::make('item')->dehydrated(),
                            ])
                            ->columns(['default' => 1, 'xl' => 3])
                            ->itemLabel(fn (array $state): string => $this->itemLabel((string) ($state['item_key'] ?? ''))),
                    ]),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('guardar')
                ->label('Guardar')
                ->icon('heroicon-o-check')
                ->action(fn () => $this->guardar()),
            Action::make('solicitud_compra')
                ->label('Solicitud de compra')
                ->icon('heroicon-o-document-plus')
                ->color('gray')
                ->visible(fn (): bool => (bool) auth()->user()?->hasPermission('requerimientos-stock.solicitud-compra'))
                ->requiresConfirmation()
                ->action(fn () => $this->guardar(comoSolicitudCompra: true)),
        ];
    }

    /** Actualiza catálogos remotos sin reemplazar el formulario editado. */
    public function refrescarDatosRestaurant(): void
    {
        try {
            $locals = $this->scopeLocalsToUser($this->gateway()->locals());
            $localId = (string) ($this->data['local_origen_id'] ?? '');
            $validIds = collect($locals)->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();

            $this->availableLocals = $locals;
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

    public function guardar(bool $comoSolicitudCompra = false): void
    {
        $this->saveError = null;

        $permission = $comoSolicitudCompra ? 'requerimientos-stock.solicitud-compra' : 'requerimientos-stock.crear';
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

        if (! $this->localAllowedForUser($localOrigenId) || ! $this->localAllowedForUser($localDestinoId)) {
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

        $items = collect($state['items'] ?? [])
            ->filter(fn (array $entry): bool => is_array($entry['item'] ?? null) && ! empty($entry['item']['item_id']) && (float) ($entry['cantidad'] ?? 0) > 0)
            ->map(fn (array $entry): array => ['item' => $entry['item'], 'cantidad' => (float) $entry['cantidad']])
            ->values()
            ->all();

        if ($items === []) {
            $this->saveError = 'Agrega al menos un ítem.';

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
            );
        } catch (Throwable $exception) {
            $this->saveError = $this->friendlyError($exception);

            return;
        } finally {
            $this->isSaving = false;
        }

        Notification::make()->title($comoSolicitudCompra ? 'Solicitud de compra guardada' : 'Requerimiento guardado')->success()->send();

        $this->data['items'] = [];
        $this->data['receptor'] = '';
        $this->data['observacion'] = '';
        $this->data['fecha'] = now()->addDay()->setTime(9, 0)->format('Y-m-d H:i:s');
    }

    private function aplicarPlantillaImportada(): void
    {
        $plantilla = session()->pull('requerimientos-stock.plantilla-importada');
        if (! is_array($plantilla)) {
            return;
        }

        $origen = (string) ($plantilla['localOrigenId'] ?? '');
        $destino = (string) ($plantilla['localDestinoId'] ?? '');
        if ($origen === '' || ! $this->localAllowedForUser($origen) || ! $this->localAllowedForUser($destino)) {
            $this->loadError = 'La plantilla no está disponible para tu usuario.';

            return;
        }

        $this->refreshAlmacenes($origen);
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
                    'cantidad' => (float) ($entry['cantidad'] ?? 1),
                ])
                ->values()
                ->all(),
        ]);
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

    private function itemLabel(string $key): string
    {
        foreach ($this->data['items'] ?? [] as $entry) {
            if (($entry['item_key'] ?? '') === $key && is_array($entry['item'] ?? null)) {
                return $this->itemDisplay($entry['item']);
            }
        }

        return 'Ítem';
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
