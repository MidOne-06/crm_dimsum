<?php

namespace App\Filament\Pages\Stock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Services\MovimientosAlmacenesGatewayClient;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\DateTimePicker;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Illuminate\Pagination\LengthAwarePaginator;
use Throwable;

class MovimientosAlmacenes extends Page implements HasTable
{
    use InteractsWithTable;
    use ScopesLocalsToUser;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';
    protected static ?string $navigationLabel = 'Listado de movimientos';
    protected static ?string $title = 'Movimientos entre almacenes';
    protected static string|\UnitEnum|null $navigationGroup = 'Movimientos entre almacenes';
    protected static ?int $navigationSort = 10;
    protected string $view = 'filament.pages.stock.movimientos-almacenes';

    public string $desde = '';
    public string $hasta = '';
    /** @var array<int, string> */
    public array $locales = [];
    /** @var array<int, string> */
    public array $items = [];
    public ?string $almacen = null;
    public string $estado = '1';
    public string $estadoRecepcion = '-1';
    public string $buscarSegun = '2';
    public ?string $restaurantLocalId = null;
    public ?string $listError = null;
    /** @var array<string, string> */
    public array $remoteItemLabels = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('movimientos-almacenes.view');
    }

    public function mount(): void
    {
        try {
            $localId = (string) (app(MovimientosAlmacenesGatewayClient::class)->contextoFiltros()['local_id'] ?? '');
            if ($localId !== '' && array_key_exists($localId, $this->localOptions()) && $this->localAllowedForUser($localId)) {
                $this->restaurantLocalId = $localId;
            }
        } catch (Throwable) {
            // El selector seguirá disponible cuando Restaurant no responda en el montaje.
        }
        $this->restablecerFiltros();
    }

    private function tableHeaderActions(): array
    {
        return [
            Action::make('actualizar')
                ->label('Actualizar')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(fn () => $this->resetTable()),
            Action::make('filtros')
                ->label('Filtros')
                ->icon('heroicon-o-adjustments-horizontal')
                ->color('gray')
                ->modalHeading('Filtros de movimientos entre almacenes')
                ->modalWidth('5xl')
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->modalSubmitActionLabel('Aplicar filtros')
                ->modalCancelActionLabel('Cancelar')
                ->extraModalFooterActions([
                    Action::make('borrar_todos')
                        ->label('Borrar todos')
                        ->color('gray')
                        ->action(function (): void {
                            $this->restablecerFiltros();
                            $this->replaceMountedAction('filtros');
                        }),
                ])
                ->fillForm(fn (): array => [
                    'desde' => $this->desde,
                    'hasta' => $this->hasta,
                    'locales' => $this->locales,
                    'items' => $this->items,
                    'almacen' => $this->almacen,
                    'estado' => $this->estado,
                    'estado_recepcion' => $this->estadoRecepcion,
                    'buscar_segun' => $this->buscarSegun,
                ])
                ->schema([
                    Grid::make(['default' => 1, 'md' => 4])->schema([
                        DatePicker::make('desde')->label('Desde')->native(false)->required(),
                        DatePicker::make('hasta')->label('Hasta')->native(false)->required(),
                        Select::make('estado')->label('Estado')->options($this->estadoOptions())->native(),
                        Select::make('estado_recepcion')->label('Estado de recepción')->options($this->estadoRecepcionOptions())->native(),
                        Select::make('locales')->label('Locales')->options(fn (): array => $this->localSelectOptions())->multiple()->searchable()->native(false)->required()->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('almacen', null);
                                $set('items', []);
                                $this->remoteItemLabels = [];
                            })->columnSpanFull(),
                        Select::make('almacen')->label('Almacén de origen')->options(fn (Get $get): array => $this->almacenOptions((array) $get('locales')))->searchable()->native(false)->placeholder('Todos')
                            ->disabled(fn (Get $get): bool => count(array_filter((array) $get('locales'))) !== 1)->columnSpan(['md' => 2]),
                        Select::make('buscar_segun')->label('Buscar según')->options(['2' => 'Local origen', '1' => 'Local destino'])->native()->columnSpan(['md' => 2]),
                        Select::make('items')->label('Contiene insumo o producto')->multiple()->searchable()->native(false)->optionsLimit(20)->maxItems(5)
                            ->getSearchResultsUsing(fn (string $search, Get $get): array => $this->itemOptions($search, (array) $get('locales')))
                            ->getOptionLabelsUsing(fn (array $values): array => $this->itemLabels($values))
                            ->placeholder('Selecciona un insumo o producto')->columnSpanFull(),
                    ]),
                ])
                ->action(function (array $data): void {
                    $desde = (string) ($data['desde'] ?? $this->desde);
                    $hasta = (string) ($data['hasta'] ?? $this->hasta);
                    if ($desde > $hasta) [$desde, $hasta] = [$hasta, $desde];

                    $options = $this->localOptions();
                    $requestedLocales = array_values((array) ($data['locales'] ?? []));
                    $this->desde = $desde;
                    $this->hasta = $hasta;
                    // El valor especial no viaja a Restaurant: se resuelve
                    // contra el catálogo en vivo, ya limitado por permisos.
                    $this->locales = in_array('__todos__', $requestedLocales, true)
                        ? ['__todos__']
                        : $this->restrictLocalIdsToUser(array_values(array_filter($requestedLocales, fn (mixed $id): bool => array_key_exists((string) $id, $options))));
                    $selectedLocalIds = $this->selectedLocalIds($this->locales);
                    $this->almacen = count($selectedLocalIds) === 1 && array_key_exists((string) ($data['almacen'] ?? ''), $this->almacenOptions($selectedLocalIds)) ? (string) $data['almacen'] : null;
                    $this->estado = array_key_exists((string) ($data['estado'] ?? ''), $this->estadoOptions()) ? (string) $data['estado'] : '1';
                    $this->estadoRecepcion = array_key_exists((string) ($data['estado_recepcion'] ?? ''), $this->estadoRecepcionOptions()) ? (string) $data['estado_recepcion'] : '-1';
                    $this->buscarSegun = in_array((string) ($data['buscar_segun'] ?? ''), ['1', '2'], true) ? (string) $data['buscar_segun'] : '2';
                    $this->items = array_slice(array_values(array_filter((array) ($data['items'] ?? []), fn (mixed $value): bool => array_key_exists((string) $value, $this->itemLabels([(string) $value])))), 0, 5);
                    $this->resetPage();
                    $this->resetTable();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->headerActions($this->tableHeaderActions())
            ->records(fn (int $page, int $recordsPerPage): LengthAwarePaginator => $this->records($page, $recordsPerPage))
            ->columns([
                TextColumn::make('id')->label('Cód.'),
                TextColumn::make('fecha')->label('Fecha')->dateTime('d/m/Y H:i'),
                TextColumn::make('local_origen')->label('Local origen')->wrap(),
                TextColumn::make('almacen_origen')->label('Almacén origen')->wrap(),
                TextColumn::make('local_destino')->label('Local destino')->wrap(),
                TextColumn::make('almacen_destino')->label('Almacén destino')->wrap(),
                TextColumn::make('encargado')->label('Encargado')->toggleable(),
                TextColumn::make('receptor')->label('Receptor')->toggleable(),
                TextColumn::make('registrado_por')->label('Registrado por')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total_items')->label('Cant. ítems')->alignEnd(),
                TextColumn::make('valorizado')->label('Valorizado')->numeric(2)->alignEnd(),
                TextColumn::make('estado')->label('Estado')->badge()->color(fn (array $record): string => $record['estado_codigo'] === '1' ? 'success' : 'gray'),
                TextColumn::make('estado_recepcion')->label('Estado recepción')->badge()->color(fn (array $record): string => in_array($record['estado_recepcion_codigo'], ['1', '2'], true) ? 'success' : 'gray'),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('detalle')
                        ->label('Ver movimiento entre almacenes')
                        ->icon('heroicon-o-eye')
                        ->modalHeading(fn (array $record): string => 'Movimiento entre almacenes #'.($record['id'] ?? ''))
                        ->modalWidth('7xl')
                        ->modalAlignment(Alignment::Start)
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Cerrar')
                        ->stickyModalHeader()
                        ->stickyModalFooter()
                        ->modalContent(fn (array $record) => view('filament.pages.stock.partials.movimiento-almacen-detalle-restaurant', $this->detalleRestaurant($record))),
                    Action::make('editar')
                        ->label('Editar movimiento')
                        ->icon('heroicon-o-pencil-square')
                        ->visible(fn (array $record): bool => (string) ($record['estado_codigo'] ?? '') === '1')
                        ->modalHeading(fn (array $record): string => 'Editar movimiento #'.($record['id'] ?? ''))
                        ->modalWidth('5xl')
                        ->stickyModalHeader()
                        ->stickyModalFooter()
                        ->modalSubmitActionLabel('Guardar cambios')
                        ->fillForm(fn (array $record): array => $this->edicionRestaurant($record))
                        ->schema([
                            Grid::make(['default' => 1, 'md' => 4])->schema([
                                DateTimePicker::make('fecha')->label('Fecha')->native(false)->required()->columnSpan(['md' => 2]),
                                TextInput::make('encargado')->label('Encargado')->maxLength(160)->columnSpan(['md' => 2]),
                                TextInput::make('receptor')->label('Receptor')->maxLength(160)->columnSpan(['md' => 2]),
                                Textarea::make('observacion')->label('Observación')->rows(3)->maxLength(1000)->columnSpanFull(),
                            ]),
                        ])
                        ->action(fn (array $record, array $data) => $this->editarMovimiento($record, $data)),
                    Action::make('anular')
                        ->label('Anular movimiento')
                        ->icon('heroicon-o-no-symbol')
                        ->color('danger')
                        ->visible(fn (array $record): bool => ($record['estado_codigo'] ?? '') === '1')
                        ->requiresConfirmation()
                        ->modalHeading('¿Anular este movimiento?')
                        ->modalDescription('Restaurant revertirá este movimiento. Esta acción no se puede deshacer.')
                        ->modalSubmitActionLabel('Anular movimiento')
                        ->action(fn (array $record) => $this->anularMovimiento($record)),
                    Action::make('descargar_pdf')
                        ->label('Descargar en PDF')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->action(fn (array $record) => $this->descargarMovimiento($record, 'normal')),
                    Action::make('descargar_pdf_v2')
                        ->label('Descargar en PDF V2')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->action(fn (array $record) => $this->descargarMovimiento($record, 'sin_costos')),
                ])
                    ->label('Operaciones')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->tooltip('Operaciones del movimiento')
                    ->color('gray')
                    ->dropdownPlacement('bottom-end')
                    ->dropdownWidth(Width::Medium),
            ])
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('No hay movimientos entre almacenes en Restaurant con los filtros seleccionados.');
    }

    /** @return array{movimiento: array<string, mixed>, error: ?string} */
    private function detalleRestaurant(array $record): array
    {
        try {
            return [
                'movimiento' => app(MovimientosAlmacenesGatewayClient::class)->detalle((string) ($record['id'] ?? '')),
                'error' => null,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'movimiento' => [],
                'error' => 'Restaurant no respondió al consultar el detalle de este movimiento.',
            ];
        }
    }

    public function anularMovimiento(array $record): void
    {
        app(MovimientosAlmacenesGatewayClient::class)->anular((string) ($record['id'] ?? ''));
        Notification::make()->success()->title('Movimiento anulado')->body('Restaurant confirmó la anulación. La lista se actualizará en tiempo real.')->send();
        $this->resetTable();
    }

    /** @return array<string, mixed> */
    private function edicionRestaurant(array $record): array
    {
        $movimiento = app(MovimientosAlmacenesGatewayClient::class)->detalle((string) ($record['id'] ?? ''));

        return [
            'fecha' => $movimiento['fecha'] ?? null,
            'encargado' => $movimiento['encargado'] ?? '',
            'receptor' => $movimiento['receptor'] ?? '',
            'observacion' => $movimiento['observacion'] ?? '',
        ];
    }

    /** @param array<string, mixed> $data */
    public function editarMovimiento(array $record, array $data): void
    {
        app(MovimientosAlmacenesGatewayClient::class)->editar((string) ($record['id'] ?? ''), $data);
        Notification::make()->success()->title('Movimiento actualizado')->body('Restaurant confirmó los cambios. La lista se actualizará en tiempo real.')->send();
        $this->resetTable();
    }

    public function descargarMovimiento(array $record, string $variant): mixed
    {
        $reporte = app(MovimientosAlmacenesGatewayClient::class)->reporte((string) ($record['id'] ?? ''), $variant);
        $suffix = $variant === 'sin_costos' ? '-sin-costos' : '';

        return response()->streamDownload(fn () => print($reporte['content']), 'movimiento-'.($record['id'] ?? '').$suffix.'.pdf', ['Content-Type' => $reporte['contentType']]);
    }

    private function records(int $page, int $recordsPerPage): LengthAwarePaginator
    {
        try {
            $result = app(MovimientosAlmacenesGatewayClient::class)->movimientos([
                ...$this->gatewayFilters(), 'pagina' => (string) $page, 'registros' => (string) $recordsPerPage,
            ]);
            $rows = collect($result['rows'] ?? [])->map(fn (array $row): array => [
                'id' => (string) ($row['id'] ?? ''), 'fecha' => $row['fecha'] ?? null,
                'local_origen' => (string) ($row['localOrigen'] ?? ''), 'almacen_origen' => (string) ($row['almacenOrigen'] ?? ''),
                'local_destino' => (string) ($row['localDestino'] ?? ''), 'almacen_destino' => (string) ($row['almacenDestino'] ?? ''),
                'encargado' => (string) ($row['encargado'] ?? ''), 'receptor' => (string) ($row['receptor'] ?? ''),
                'registrado_por' => (string) ($row['registradoPor'] ?? ''), 'total_items' => (int) ($row['totalItems'] ?? 0),
                'valorizado' => (float) ($row['valorizado'] ?? 0), 'estado_codigo' => (string) ($row['estadoCodigo'] ?? ''),
                'estado' => (string) ($row['estado'] ?? ''), 'estado_recepcion_codigo' => (string) ($row['estadoRecepcionCodigo'] ?? ''),
                'estado_recepcion' => (string) ($row['estadoRecepcion'] ?? ''),
            ])->filter(fn (array $row): bool => $row['id'] !== '')->values();
            $this->listError = null;

            return new LengthAwarePaginator($rows, (int) ($result['total'] ?? $rows->count()), $recordsPerPage, $page, ['path' => request()->url(), 'pageName' => 'movimientosAlmacenesPage']);
        } catch (Throwable $exception) {
            report($exception);
            $this->listError = 'Restaurant no respondió al consultar los movimientos entre almacenes. Intenta actualizar nuevamente.';

            return new LengthAwarePaginator(collect(), 0, $recordsPerPage, $page, ['path' => request()->url(), 'pageName' => 'movimientosAlmacenesPage']);
        }
    }

    /** @return array<string, string> */
    private function localOptions(): array
    {
        try {
            return collect($this->scopeLocalsToUser(app(MovimientosAlmacenesGatewayClient::class)->locales()))
                ->mapWithKeys(fn (array $local): array => [(string) ($local['id'] ?? '') => (string) ($local['name'] ?? '')])
                ->filter(fn (string $name, string $id): bool => $id !== '' && $name !== '')->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string, string> */
    private function localSelectOptions(): array
    {
        return ['__todos__' => 'Todos los locales'] + $this->localOptions();
    }

    /** @param array<int, string> $localIds @return array<int, string> */
    private function selectedLocalIds(array $localIds): array
    {
        if (in_array('__todos__', $localIds, true)) {
            return array_keys($this->localOptions());
        }

        return $this->restrictLocalIdsToUser(array_values(array_filter(
            $localIds,
            fn (mixed $id): bool => array_key_exists((string) $id, $this->localOptions()),
        )));
    }

    /** @param array<int, string> $localIds @return array<string, string> */
    private function almacenOptions(array $localIds): array
    {
        $localIds = $this->selectedLocalIds($localIds);
        $localId = (string) (collect($localIds)->filter()->first() ?? '');
        if (count(array_filter($localIds)) !== 1 || $localId === '' || ! $this->localAllowedForUser($localId)) return [];
        try {
            return collect(app(MovimientosAlmacenesGatewayClient::class)->almacenes($localId))
                ->mapWithKeys(fn (array $warehouse): array => [(string) ($warehouse['id'] ?? '') => (string) ($warehouse['name'] ?? '')])
                ->filter(fn (string $name, string $id): bool => $id !== '' && $name !== '')->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<int, string> $localIds @return array<string, string> */
    private function itemOptions(string $search, array $localIds): array
    {
        $localIds = $this->selectedLocalIds($localIds);
        $localId = (string) (collect($localIds)->filter()->first() ?? '');
        if (mb_strlen(trim($search)) < 2 || $localId === '' || ! $this->localAllowedForUser($localId)) return [];
        try {
            return collect(app(MovimientosAlmacenesGatewayClient::class)->items($search, $localId))->mapWithKeys(function (array $item): array {
                $key = (string) ($item['item_tipo'] ?? '').':'.(string) ($item['id'] ?? '');
                $label = trim((filled($item['codigo'] ?? null) ? $item['codigo'].' · ' : '').($item['descripcion'] ?? '').(filled($item['presentacion'] ?? null) ? ' · '.$item['presentacion'] : ''));
                if ($key === ':' || $label === '') return [];
                $this->remoteItemLabels[$key] = $label;
                return [$key => $label];
            })->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<int, string> $values @return array<string, string> */
    private function itemLabels(array $values): array
    {
        return collect($values)->filter(fn (mixed $value): bool => isset($this->remoteItemLabels[(string) $value]))
            ->mapWithKeys(fn (mixed $value): array => [(string) $value => $this->remoteItemLabels[(string) $value]])->all();
    }

    /** @return array<string, string> */
    private function estadoOptions(): array
    {
        return ['-1' => 'Todos', '1' => 'Activo', '0' => 'Anulado'];
    }

    /** @return array<string, string> */
    private function estadoRecepcionOptions(): array
    {
        // Contrato capturado de Restaurant: 2 = recepcionado; 1 queda para
        // movimientos pendientes de recepción. No se usa 0, pues Logística
        // lo interpreta como ausencia de filtro.
        return ['-1' => 'Todos', '1' => 'Pendiente', '2' => 'Recepcionado'];
    }

    private function restablecerFiltros(): void
    {
        $this->desde = now()->startOfMonth()->toDateString();
        $this->hasta = now()->endOfMonth()->toDateString();
        $this->locales = $this->restaurantLocalId ? [$this->restaurantLocalId] : [];
        $this->items = [];
        $this->remoteItemLabels = [];
        $this->almacen = null;
        $this->estado = '1';
        $this->estadoRecepcion = '-1';
        $this->buscarSegun = '2';
    }

    /** @return array<string, string> */
    private function gatewayFilters(): array
    {
        return [
            'fecha_inicio' => $this->desde, 'fecha_fin' => $this->hasta, 'locales' => implode(',', $this->selectedLocalIds($this->locales)),
            'items' => implode(',', $this->items), 'almacen' => $this->almacen ?? '-1', 'estado' => $this->estado,
            'estado_recepcion' => $this->estadoRecepcion, 'buscar_segun' => $this->buscarSegun,
        ];
    }
}
