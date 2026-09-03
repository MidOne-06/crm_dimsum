<?php

namespace App\Filament\Pages\Stock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Services\GuiasInternasGatewayClient;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Throwable;

class GuiasInternas extends Page implements HasTable
{
    use InteractsWithTable;
    use ScopesLocalsToUser;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationLabel = 'Listado de guías';
    protected static ?string $title = 'Guías internas';
    protected static string|\UnitEnum|null $navigationGroup = 'Guías internas';
    protected static ?int $navigationSort = 10;
    protected string $view = 'filament.pages.stock.guias-internas';

    public string $desde = '';
    public string $hasta = '';
    public string $activeDatePreset = 'last30';
    public string $fechaTipo = '1';
    public string $buscarSegun = '1';
    public ?string $restaurantLocalId = null;
    /** @var array<int, string> */
    public array $localesOrigen = [];
    /** @var array<int, string> */
    public array $items = [];
    public ?string $almacen = null;
    public ?string $serie = null;
    public ?string $numero = null;
    public ?string $codigo = null;
    public ?string $motivo = null;
    public ?string $estado = '1';
    public ?string $listError = null;
    /** @var array<string, string> */
    public array $remoteItemLabels = [];

    public static function canAccess(): bool { return (bool) auth()->user()?->hasPermission('guias-internas.view'); }

    public function mount(): void
    {
        try {
            $localId = (string) (app(GuiasInternasGatewayClient::class)->contextoFiltros()['local_id'] ?? '');
            if ($localId !== '' && array_key_exists($localId, $this->restaurantLocalesOptions()) && $this->localAllowedForUser($localId)) {
                $this->restaurantLocalId = $localId;
            }
        } catch (\Throwable) {
            // La copia local sigue siendo consultable si Restaurant no responde durante el montaje.
        }

        $this->restablecerFiltrosRestaurant();
    }
    public function setDateRange(string $start, string $end, ?string $preset = 'custom'): void { $this->desde = $start; $this->hasta = $end; $this->activeDatePreset = $preset ?: 'custom'; $this->resetPage(); $this->resetTable(); }
    /** Refresca la tabla desde Restaurant; no escribe en la copia local. */
    public function actualizarListado(): void
    {
        $this->resetPage();
        $this->resetTable();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('filtros')
                ->label('Filtros')
                ->icon('heroicon-o-adjustments-horizontal')
                ->color('gray')
                ->modalHeading('Filtros de guías internas')
                ->modalWidth('5xl')
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->modalSubmitActionLabel('Aplicar filtros')
                ->modalCancelActionLabel('Cancelar')
                ->extraModalFooterActions([
                    Action::make('restablecer_filtros')
                        ->label('Borrar todos')
                        ->color('gray')
                        ->action(function (): void {
                            $this->restablecerFiltrosRestaurant();
                            $this->replaceMountedAction('filtros');
                        }),
                ])
                ->fillForm(fn (): array => [
                    'desde' => $this->desde,
                    'hasta' => $this->hasta,
                    'fecha_tipo' => $this->fechaTipo,
                    'buscar_segun' => $this->buscarSegun,
                    'locales_origen' => $this->localesOrigen,
                    'almacen' => $this->almacen,
                    'serie' => $this->serie,
                    'numero' => $this->numero,
                    'codigo' => $this->codigo,
                    'motivo' => $this->motivo,
                    'items' => $this->items,
                    'estado' => $this->estado ?? '1',
                ])
                ->schema([
                    Grid::make(['default' => 1, 'md' => 4])->schema([
                        Select::make('locales_origen')->label('Locales de origen')->options(fn (): array => $this->restaurantLocalesOptions())->multiple()->searchable()->native(false)->required()->live()
                            ->afterStateUpdated(function (mixed $state, Set $set): void {
                                $set('almacen', null);
                                $set('items', []);
                                $this->remoteItemLabels = [];
                            })->columnSpanFull(),
                        Select::make('items')->label('Contiene insumo o producto')->multiple()->searchable()->native(false)->optionsLimit(20)->maxItems(5)
                            ->getSearchResultsUsing(fn (string $search, Get $get): array => $this->itemOptions($search, (array) $get('locales_origen')))
                            ->getOptionLabelsUsing(fn (array $values): array => $this->itemLabels($values))
                            ->placeholder('Selecciona un insumo o producto')->columnSpanFull(),
                        Select::make('almacen')->label('Almacén de origen')->options(fn (Get $get): array => $this->restaurantWarehouseOptions((array) $get('locales_origen')))->searchable()->native(false)->placeholder('Todos')
                            ->disabled(fn (Get $get): bool => count(array_filter((array) $get('locales_origen'))) !== 1)
                            ->columnSpan(['md' => 2]),
                        Select::make('buscar_segun')->label('Buscar según')->options(['1' => 'Local de origen', '2' => 'Local de destino'])->native()->columnSpan(['md' => 2]),
                        Grid::make(['default' => 1, 'md' => 3])->schema([
                            TextInput::make('serie')->label('Serie')->maxLength(20),
                            TextInput::make('numero')->label('Número')->maxLength(30),
                            TextInput::make('codigo')->label('Código')->maxLength(30),
                        ])->columnSpan(['md' => 2]),
                        Grid::make(['default' => 1, 'md' => 3])->schema([
                            Select::make('fecha_tipo')->label('Fecha')->options(['1' => 'De emisión', '0' => 'De traslado'])->native(),
                            DatePicker::make('desde')->label('Desde')->native(false)->required(),
                            DatePicker::make('hasta')->label('Hasta')->native(false)->required(),
                        ])->columnSpan(['md' => 2]),
                        Select::make('motivo')->label('Motivo')->options(fn (): array => $this->restaurantMotivoOptions())->native()->placeholder('Todos')->columnSpan(['md' => 2]),
                        Select::make('estado')->label('Estado')->options(fn (): array => $this->restaurantEstadoOptions())->native()->columnSpan(['md' => 2]),
                    ]),
                ])
                ->action(function (array $data): void {
                    $desde = (string) ($data['desde'] ?? $this->desde);
                    $hasta = (string) ($data['hasta'] ?? $this->hasta);

                    if ($desde > $hasta) {
                        [$desde, $hasta] = [$hasta, $desde];
                    }

                    $this->desde = $desde;
                    $this->hasta = $hasta;
                    $this->fechaTipo = in_array((string) ($data['fecha_tipo'] ?? ''), ['0', '1'], true) ? (string) $data['fecha_tipo'] : '1';
                    $this->buscarSegun = in_array((string) ($data['buscar_segun'] ?? ''), ['1', '2'], true) ? (string) $data['buscar_segun'] : '1';
                    $this->localesOrigen = $this->restrictLocalIdsToUser(array_values(array_filter((array) ($data['locales_origen'] ?? []), fn (mixed $id): bool => array_key_exists((string) $id, $this->restaurantLocalesOptions()))));
                    $this->almacen = count($this->localesOrigen) === 1 && array_key_exists((string) ($data['almacen'] ?? ''), $this->restaurantWarehouseOptions($this->localesOrigen)) ? (string) $data['almacen'] : null;
                    $this->serie = $this->filterText($data['serie'] ?? null, 20);
                    $this->numero = $this->filterText($data['numero'] ?? null, 30);
                    $this->codigo = $this->filterText($data['codigo'] ?? null, 30);
                    $this->motivo = array_key_exists((string) ($data['motivo'] ?? ''), $this->restaurantMotivoOptions()) ? (string) $data['motivo'] : null;
                    $this->items = array_values(array_filter((array) ($data['items'] ?? []), fn (mixed $id): bool => array_key_exists((string) $id, $this->itemLabels([(string) $id]))));
                    $this->items = array_slice($this->items, 0, 5);
                    $this->estado = array_key_exists((string) ($data['estado'] ?? ''), $this->restaurantEstadoOptions()) ? (string) $data['estado'] : '1';
                    // El listado operativo siempre se vuelve a pedir a
                    // Restaurant. La copia local se reserva para extracción
                    // e informes matriciales.
                    $this->resetPage();
                    $this->resetTable();
                }),
            ActionGroup::make([
                Action::make('nueva_guia')
                    ->label('Nueva guía interna')
                    ->icon('heroicon-o-document-plus')
                    ->visible(fn (): bool => (bool) auth()->user()?->hasPermission('guias-internas.crear'))
                    ->url(fn (): string => NuevaGuiaInterna::getUrl()),
                Action::make('exportar_excel')
                    ->label('Descargar en Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (): bool => (bool) auth()->user()?->hasPermission('guias-internas.descargar'))
                    ->action(fn () => $this->exportarExcel()),
                Action::make('exportar_excel_batch')
                    ->label('Descargar en Excel BATCH')
                    ->icon('heroicon-o-document-arrow-down')
                    ->visible(fn (): bool => (bool) auth()->user()?->hasPermission('guias-internas.descargar'))
                    ->requiresConfirmation()
                    ->modalHeading('Generar Excel BATCH')
                    ->modalDescription('Restaurant preparará el archivo con los filtros activos. Podrás descargarlo cuando finalice el proceso BATCH.')
                    ->modalWidth('lg')
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->modalSubmitActionLabel('Generar')
                    ->modalCancelActionLabel('Cancelar')
                    ->action(function (): void {
                        $this->exportarExcelBatch();
                        $this->replaceMountedAction('reportes_excel_batch');
                    }),
                Action::make('reportes_excel_batch')
                    ->label('Ver reportes Excel BATCH')
                    ->icon('heroicon-o-queue-list')
                    ->visible(fn (): bool => (bool) auth()->user()?->hasPermission('guias-internas.descargar'))
                    ->modalHeading('Reportes Excel BATCH')
                    ->modalWidth('5xl')
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar')
                    ->modalContent(fn () => view('filament.pages.stock.partials.reportes-excel-batch', $this->reportesExcelBatch())),
                Action::make('actualizar')
                    ->label('Actualizar')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (): bool => (bool) auth()->user()?->hasPermission('guias-internas.view'))
                    ->action(fn () => $this->actualizarListado()),
                Action::make('extraccion')
                    ->label('Extracción')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (): bool => (bool) auth()->user()?->hasPermission('guias-internas.sincronizar'))
                    ->url(fn (): string => ExtraccionGuiasInternas::getUrl()),
                Action::make('reporte')
                    ->label('Reporte')
                    ->icon('heroicon-o-table-cells')
                    ->visible(fn (): bool => (bool) auth()->user()?->hasPermission('guias-internas.reporte.view'))
                    ->url(fn (): string => ReporteGuiasInternas::getUrl()),
            ])
                ->label('Operaciones')
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->dropdownPlacement('bottom-end')
                ->dropdownWidth(Width::Medium),
        ];
    }

    public function table(Table $table): Table
    {
        return $table->records(fn (int $page, int $recordsPerPage): LengthAwarePaginator => $this->records($page, $recordsPerPage))->columns([
            TextColumn::make('id')->label('Cód.'), TextColumn::make('serie')->label('Serie'), TextColumn::make('correlativo')->label('Número'),
            TextColumn::make('fecha_emision')->label('Emisión')->dateTime('d/m/Y H:i'), TextColumn::make('local_origen')->label('Origen')->wrap(),
            TextColumn::make('almacen')->label('Almacén')->toggleable(), TextColumn::make('local_destino')->label('Destino')->wrap(),
            TextColumn::make('total_items')->label('Ítems')->alignEnd(), TextColumn::make('total')->label('Total')->numeric(2)->alignEnd(),
            TextColumn::make('estado')->label('Estado')->badge()->color(fn (array $record) => ($record['estado_codigo'] ?? '') === '1' ? 'success' : 'gray'),
        ])->recordActions([
            ActionGroup::make([
            Action::make('detalle')->label('Ver guía interna')->icon('heroicon-o-eye')->visible(fn () => auth()->user()?->hasPermission('guias-internas.ver-detalle'))
                ->modalHeading(fn (array $record) => 'Guía interna #'.($record['id'] ?? ''))
                ->modalWidth('7xl')->modalAlignment(Alignment::Start)->modalSubmitAction(false)->modalCancelActionLabel('Cerrar')->stickyModalHeader()->stickyModalFooter()
                ->modalContent(fn (array $record) => view('filament.pages.stock.partials.guia-interna-detalle-restaurant', $this->detalleRestaurant($record))),
            Action::make('anular')->label('Anular guía interna')->icon('heroicon-o-no-symbol')->color('danger')
                ->visible(fn (array $record) => ($record['estado_codigo'] ?? '') === '1' && (bool) auth()->user()?->hasPermission('guias-internas.anular'))
                ->requiresConfirmation()->modalHeading('¿Anular esta guía interna?')->modalDescription('Esta operación se realizará en Restaurant y no se puede deshacer.')
                ->modalWidth('lg')->stickyModalHeader()->stickyModalFooter()
                ->schema([Checkbox::make('devolver_cantidades')->label('Devolver las cantidades adquiridas')->default(true)])
                ->action(fn (array $record, array $data) => $this->anularGuia($record, (bool) ($data['devolver_cantidades'] ?? true))),
            ActionGroup::make([
                $this->downloadAction('trabajo', 'Descargar guía interna de trabajo'),
                $this->downloadAction('guia', 'Descargar guía interna'),
                $this->downloadAction('guia_v2', 'Descargar guía interna V2'),
                $this->downloadAction('guia_sin_precio', 'Descargar guía interna (sin precio)'),
                $this->downloadAction('guia_v2_sin_precio', 'Descargar guía interna V2 (sin precio)'),
                $this->downloadAction('matricial', 'Descargar guía interna matricial'),
                $this->downloadAction('imprimir_matricial', 'Imprimir guía interna matricial'),
                $this->downloadAction('csv', 'Descargar guía interna CSV'),
            ])
                ->label('Descargar')
                ->icon('heroicon-o-arrow-down-tray')
                ->dropdownWidth(Width::Medium)
                ->dropdownPlacement('left-start')
                ->visible(fn () => auth()->user()?->hasPermission('guias-internas.descargar')),
            ])->icon('heroicon-o-cog-6-tooth')->tooltip('Operaciones de guía')->color('gray')->dropdownPlacement('bottom-end')->dropdownWidth('xs'),
        ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('canjear_por_movimiento')
                        ->label('Canjear por movimiento interno')
                        ->icon('heroicon-o-arrow-path-rounded-square')
                        ->url(fn (Collection $records): string => 'https://corporaciondimsum.restaurant.pe/restaurant/logistica.html#!/movimientoalmacen/canjeguias/'.implode(',', $records->pluck('id')->filter()->all()))
                        ->openUrlInNewTab(),
                    BulkAction::make('agrupar_guias')
                        ->label('Agrupar selección')
                        ->icon('heroicon-o-rectangle-stack')
                        ->requiresConfirmation()
                        ->modalHeading('Agrupar guías internas')
                        ->modalDescription('Restaurant creará una nueva guía consolidada y esta operación no se puede revertir.')
                        ->modalSubmitActionLabel('Agrupar')
                        ->action(function (Collection $records): void {
                            $ids = $records->pluck('id')->filter()->map(fn ($id): string => (string) $id)->values()->all();
                            app(GuiasInternasGatewayClient::class)->agrupar($ids);
                            Notification::make()->success()->title('Guías agrupadas')->body('Restaurant confirmó la agrupación. La lista se actualizará desde Restaurant.')->send();
                            $this->resetTable();
                        }),
                ])->label('Operaciones de selección')->icon('heroicon-o-cog-6-tooth'),
            ])
            ->paginated([10, 25, 50, 100])->defaultPaginationPageOption(25)->emptyStateHeading('No hay guías internas en Restaurant con los filtros seleccionados.');
    }

    /**
     * El listado operativo no modela ni consulta guias_internas: cada página
     * viene del mismo endpoint de Logística que usa Restaurant. La base local
     * queda exclusivamente para extracción histórica y reporte matricial.
     */
    private function records(int $page, int $recordsPerPage): LengthAwarePaginator
    {
        try {
            $result = app(GuiasInternasGatewayClient::class)->guias([
                ...$this->gatewayFilters(),
                'pagina' => (string) $page,
                'registros' => (string) $recordsPerPage,
            ]);

            $rows = collect($result['rows'] ?? [])
                ->map(fn (array $row): array => $this->mapRestaurantRow($row))
                ->filter(fn (array $row): bool => $row['id'] !== '')
                ->values();
            $this->listError = null;

            return new LengthAwarePaginator(
                $rows,
                (int) ($result['total'] ?? $rows->count()),
                $recordsPerPage,
                $page,
                ['path' => request()->url(), 'pageName' => 'guiasInternasPage'],
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->listError = 'Restaurant no respondió al consultar las guías. Intenta actualizar nuevamente.';

            return new LengthAwarePaginator(collect(), 0, $recordsPerPage, $page, [
                'path' => request()->url(),
                'pageName' => 'guiasInternasPage',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function mapRestaurantRow(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'serie' => (string) ($row['serie'] ?? ''),
            'correlativo' => (string) ($row['correlativo'] ?? ''),
            'fecha_registro' => $row['fechaRegistro'] ?? null,
            'fecha_emision' => $row['fechaEmision'] ?? null,
            'fecha_traslado' => $row['fechaTraslado'] ?? null,
            'local_origen_id' => (string) ($row['localOrigenId'] ?? ''),
            'local_origen' => (string) ($row['localOrigen'] ?? ''),
            'local_destino_id' => (string) ($row['localDestinoId'] ?? ''),
            'local_destino' => (string) ($row['localDestino'] ?? ''),
            'almacen_id' => (string) ($row['almacenId'] ?? ''),
            'almacen' => (string) ($row['almacen'] ?? ''),
            'motivo_id' => (string) ($row['motivoId'] ?? ''),
            'motivo' => (string) ($row['motivo'] ?? ''),
            'estado_codigo' => (string) ($row['estadoCodigo'] ?? ''),
            'estado' => (string) ($row['estado'] ?? ''),
            'recepcionada' => (string) ($row['recepcionada'] ?? ''),
            'total_items' => (int) ($row['totalItems'] ?? 0),
            'total' => (float) ($row['total'] ?? 0),
        ];
    }

    /** @return array{guia: array<string, mixed>, error: ?string} */
    private function detalleRestaurant(array $record): array
    {
        try {
            return ['guia' => app(GuiasInternasGatewayClient::class)->detalle((string) ($record['id'] ?? '')), 'error' => null];
        } catch (Throwable $exception) {
            report($exception);

            return ['guia' => [], 'error' => 'Restaurant no respondió al cargar el detalle de esta guía.'];
        }
    }

    /** @return array<string, string> */
    private function restaurantLocalesOptions(): array
    {
        try {
            return collect($this->scopeLocalsToUser(app(GuiasInternasGatewayClient::class)->locales()))
                ->mapWithKeys(fn (array $local): array => [(string) ($local['id'] ?? '') => (string) ($local['name'] ?? '')])
                ->filter(fn (string $name, string $id): bool => $id !== '' && $name !== '')
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<int, string> $localIds @return array<string, string> */
    private function restaurantWarehouseOptions(array $localIds): array
    {
        if (count(array_filter($localIds)) !== 1) return [];

        $localId = (string) (collect($localIds)->filter()->first() ?? $this->restaurantLocalId ?? '');
        if ($localId === '' || ! $this->localAllowedForUser($localId)) return [];

        try {
            return collect(app(GuiasInternasGatewayClient::class)->almacenes($localId))
                ->mapWithKeys(fn (array $warehouse): array => [(string) ($warehouse['id'] ?? '') => (string) ($warehouse['name'] ?? '')])
                ->filter(fn (string $name, string $id): bool => $id !== '' && $name !== '')
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<string, string> */
    private function restaurantMotivoOptions(): array
    {
        try {
            return collect(app(GuiasInternasGatewayClient::class)->motivos())
                ->mapWithKeys(fn (array $motivo): array => [(string) ($motivo['id'] ?? '') => (string) ($motivo['name'] ?? '')])
                ->filter(fn (string $name, string $id): bool => $id !== '' && $name !== '')
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<string, string> */
    private function restaurantEstadoOptions(): array
    {
        try {
            return collect(app(GuiasInternasGatewayClient::class)->estados())
                ->mapWithKeys(fn (array $estado): array => [(string) ($estado['id'] ?? '') => (string) ($estado['name'] ?? '')])
                ->filter(fn (string $name, string $id): bool => $id !== '' && $name !== '')
                ->all();
        } catch (\Throwable) {
            return ['-1' => 'Todos', '1' => 'Activa', '2' => 'Importada', '0' => 'Anulada', '3' => 'Agrupada', '4' => 'Sin Facturar'];
        }
    }

    /** @param array<int, string> $localIds @return array<string, string> */
    private function itemOptions(string $search, array $localIds = []): array
    {
        $search = trim($search);
        $localId = (string) (collect($localIds)->filter()->first() ?? $this->restaurantLocalId ?? '');
        if (mb_strlen($search) < 2 || $localId === '' || ! $this->localAllowedForUser($localId)) return [];

        try {
            $options = collect(app(GuiasInternasGatewayClient::class)->items($search, $localId))
                ->mapWithKeys(function (array $item): array {
                    $key = $this->itemValue($item['item_tipo'] ?? '', $item['id'] ?? $item['item_id'] ?? '');
                    $label = trim((filled($item['codigo'] ?? null) ? $item['codigo'].' · ' : '').($item['descripcion'] ?? $item['item_descripcion'] ?? ''));
                    if ($key === ':' || $label === '') return [];
                    $this->remoteItemLabels[$key] = $label;

                    return [$key => $label];
                })
                ->all();

            return $options;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<int, string> $values @return array<string, string> */
    private function itemLabels(array $values): array
    {
        return collect($values)
            ->filter(fn (mixed $value): bool => isset($this->remoteItemLabels[(string) $value]))
            ->mapWithKeys(fn (mixed $value): array => [(string) $value => $this->remoteItemLabels[(string) $value]])
            ->all();
    }

    private function filterText(mixed $value, int $maxLength): ?string
    {
        $value = mb_substr(trim((string) $value), 0, $maxLength);

        return $value !== '' ? $value : null;
    }

    /** @param array<int, string>|null $values @return array<int, array{tipo: string, id: string}> */
    private function itemPairs(?array $values = null): array
    {
        return collect($values ?? $this->items)
            ->map(function (mixed $value): ?array {
                [$tipo, $id] = array_pad(explode(':', (string) $value, 2), 2, '');

                return $tipo !== '' && $id !== '' ? ['tipo' => $tipo, 'id' => $id] : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    private function itemValue(mixed $tipo, mixed $id): string
    {
        return (string) $tipo.':'.(string) $id;
    }

    private function restablecerFiltrosRestaurant(): void
    {
        $this->desde = now()->subDays(30)->toDateString();
        $this->hasta = now()->toDateString();
        $this->activeDatePreset = 'last30';
        $this->fechaTipo = '1';
        $this->buscarSegun = '1';
        $this->localesOrigen = $this->restaurantLocalId ? [$this->restaurantLocalId] : [];
        $this->items = [];
        $this->remoteItemLabels = [];
        $this->almacen = null;
        $this->serie = null;
        $this->numero = null;
        $this->codigo = null;
        $this->motivo = null;
        $this->estado = '1';
    }

    private function downloadAction(string $variant, string $label): Action
    {
        return Action::make('descargar_'.$variant)->label($label)->icon('heroicon-o-arrow-down-tray')
            ->action(fn (array $record) => $this->descargarGuia($record, $variant));
    }

    public function descargarGuia(array $record, string $variant): mixed
    {
        $reporte = app(GuiasInternasGatewayClient::class)->reporte((string) ($record['id'] ?? ''), $variant);
        $extension = $variant === 'csv' ? 'csv' : 'pdf';
        return response()->streamDownload(fn () => print($reporte['content']), 'guia-interna-'.($record['serie'] ?? '').'-'.($record['correlativo'] ?? '').'.'.$extension, ['Content-Type' => $reporte['contentType']]);
    }

    public function exportarExcel(): mixed
    {
        abort_unless(auth()->user()?->hasPermission('guias-internas.descargar'), 403);

        $reporte = app(GuiasInternasGatewayClient::class)->exportarExcel($this->gatewayFilters());

        return response()->streamDownload(
            fn () => print($reporte['content']),
            'Informe_guiaremision_'.now()->format('Y-m-d_His').'.xlsx',
            ['Content-Type' => $reporte['contentType']],
        );
    }

    public function exportarExcelBatch(): void
    {
        abort_unless(auth()->user()?->hasPermission('guias-internas.descargar'), 403);

        $resultado = app(GuiasInternasGatewayClient::class)->solicitarExcelBatch($this->gatewayFilters());
        $mensaje = (string) ($resultado['mensajes'][0] ?? 'Restaurant inició la generación del reporte BATCH.');

        Notification::make()
            ->success()
            ->title('Excel BATCH solicitado')
            ->body($mensaje)
            ->send();
    }

    /** @return array{pendientes: array<int, array<string, mixed>>, terminados: array<int, array<string, mixed>>} */
    public function reportesExcelBatch(): array
    {
        abort_unless(auth()->user()?->hasPermission('guias-internas.descargar'), 403);

        $reportes = app(GuiasInternasGatewayClient::class)->reportesExcelBatch();

        return [
            'pendientes' => array_values((array) ($reportes['pendientes'] ?? [])),
            'terminados' => array_values((array) ($reportes['terminados'] ?? [])),
        ];
    }

    /** @return array<string, string> */
    private function gatewayFilters(): array
    {
        $itemPairs = $this->itemPairs();

        return [
            'fecha_inicio' => $this->desde,
            'fecha_fin' => $this->hasta,
            'locales' => implode(',', $this->localesOrigen),
            'estado' => $this->estado ?? '1',
            'motivo' => $this->motivo ?? '-1',
            'buscar_segun' => $this->buscarSegun,
            'almacen' => $this->almacen ?? '-1',
            'serie' => $this->serie ?? '',
            'numero' => $this->numero ?? '',
            'codigo' => $this->codigo ?? '',
            'filtro_por_fecha' => $this->fechaTipo,
            'item_ids' => implode('-', array_column($itemPairs, 'id')),
            'item_tipos' => implode('-', array_column($itemPairs, 'tipo')),
        ];
    }

    public function anularGuia(array $record, bool $devolverCantidades): void
    {
        app(GuiasInternasGatewayClient::class)->anular((string) ($record['id'] ?? ''), $devolverCantidades);
        Notification::make()->success()->title('Guía interna anulada')->body('Restaurant confirmó la anulación. El listado se actualizará desde Restaurant.')->send();
        $this->resetTable();
    }
}
