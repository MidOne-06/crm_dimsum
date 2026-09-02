<?php

namespace App\Filament\Pages\Stock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Models\GuiaInterna;
use App\Models\GuiaInternaDetalle;
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
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Artisan;

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
    public string $fechaTipo = 'fecha_emision';
    public string $buscarSegun = '1';
    /** @var array<int, string> */
    public array $localesOrigen = [];
    /** @var array<int, string> */
    public array $items = [];
    public ?string $almacen = null;
    public ?string $serie = null;
    public ?string $numero = null;
    public ?string $codigo = null;
    public ?string $motivo = null;
    public ?string $estado = null;

    public static function canAccess(): bool { return (bool) auth()->user()?->hasPermission('guias-internas.view'); }

    public function mount(): void { $this->desde = now()->subDays(30)->toDateString(); $this->hasta = now()->toDateString(); }
    public function setDateRange(string $start, string $end, ?string $preset = 'custom'): void { $this->desde = $start; $this->hasta = $end; $this->activeDatePreset = $preset ?: 'custom'; $this->resetTable(); }
    public function sincronizar(): void { abort_unless(auth()->user()?->hasPermission('guias-internas.sincronizar'), 403); Artisan::call('guias-internas:sincronizar', ['--desde' => $this->desde, '--hasta' => $this->hasta]); $this->resetTable(); }

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
                    'estado' => $this->estado ?? '',
                ])
                ->schema([
                    Grid::make(['default' => 1, 'md' => 4])->schema([
                        Select::make('fecha_tipo')->label('Filtrar fecha por')->options([
                            'fecha_emision' => 'Fecha de emisión',
                            'fecha_registro' => 'Fecha de registro',
                            'fecha_traslado' => 'Fecha de traslado',
                        ])->native(),
                        DatePicker::make('desde')->label('Desde')->native(false)->required(),
                        DatePicker::make('hasta')->label('Hasta')->native(false)->required(),
                        Select::make('estado')->label('Estado')->options(['' => 'Todos', '1' => 'Activa', '0' => 'Anulada'])->native(),
                        Select::make('locales_origen')->label('Locales de origen')->options($this->locales())->multiple()->searchable()->native(false)->placeholder('Todos los locales')->columnSpan(['md' => 2]),
                        Select::make('buscar_segun')->label('Buscar según')->options(['1' => 'Local de origen', '2' => 'Local de destino'])->native(),
                        Select::make('almacen')->label('Almacén de origen')->options($this->almacenOptions())->searchable()->native(false)->placeholder('Todos los almacenes'),
                        Select::make('motivo')->label('Motivo')->options($this->motivoOptions())->native()->placeholder('Todos'),
                        TextInput::make('serie')->label('Serie')->maxLength(20),
                        TextInput::make('numero')->label('Número')->maxLength(30),
                        TextInput::make('codigo')->label('Código')->maxLength(30),
                        Select::make('items')->label('Contiene insumo o producto')->multiple()->searchable()->native(false)->optionsLimit(20)->maxItems(5)
                            ->getSearchResultsUsing(fn (string $search): array => $this->itemOptions($search))
                            ->getOptionLabelsUsing(fn (array $values): array => $this->itemLabels($values))
                            ->placeholder('Buscar por nombre o código')->columnSpan(['md' => 4]),
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
                    $this->fechaTipo = in_array((string) ($data['fecha_tipo'] ?? ''), ['fecha_emision', 'fecha_registro', 'fecha_traslado'], true) ? (string) $data['fecha_tipo'] : 'fecha_emision';
                    $this->buscarSegun = in_array((string) ($data['buscar_segun'] ?? ''), ['1', '2'], true) ? (string) $data['buscar_segun'] : '1';
                    $this->localesOrigen = $this->restrictLocalIdsToUser(array_values(array_filter((array) ($data['locales_origen'] ?? []), fn (mixed $id): bool => array_key_exists((string) $id, $this->locales()))));
                    $this->almacen = array_key_exists((string) ($data['almacen'] ?? ''), $this->almacenOptions()) ? (string) $data['almacen'] : null;
                    $this->serie = $this->filterText($data['serie'] ?? null, 20);
                    $this->numero = $this->filterText($data['numero'] ?? null, 30);
                    $this->codigo = $this->filterText($data['codigo'] ?? null, 30);
                    $this->motivo = array_key_exists((string) ($data['motivo'] ?? ''), $this->motivoOptions()) ? (string) $data['motivo'] : null;
                    $this->items = array_values(array_filter((array) ($data['items'] ?? []), fn (mixed $id): bool => array_key_exists((string) $id, $this->itemLabels([(string) $id]))));
                    $this->items = array_slice($this->items, 0, 5);
                    $this->estado = in_array((string) ($data['estado'] ?? ''), ['', '0', '1'], true) ? (string) ($data['estado'] ?? '') : null;
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
                    ->visible(fn (): bool => (bool) auth()->user()?->hasPermission('guias-internas.sincronizar'))
                    ->requiresConfirmation()
                    ->modalHeading('Actualizar guías internas')
                    ->modalDescription('Se sincronizará la copia local con Restaurant para el rango de fechas seleccionado.')
                    ->modalWidth('lg')
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->modalSubmitActionLabel('Actualizar')
                    ->modalCancelActionLabel('Cancelar')
                    ->action(fn () => $this->sincronizar()),
                Action::make('extraccion')
                    ->label('Extracción')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (): bool => (bool) auth()->user()?->hasPermission('guias-internas.sincronizar'))
                    ->url(fn (): string => ExtraccionGuiasInternas::getUrl()),
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
        return $table->query(fn (): Builder => $this->query())->columns([
            TextColumn::make('restaurant_id')->label('Cód.')->sortable(), TextColumn::make('serie')->label('Serie'), TextColumn::make('correlativo')->label('Número'),
            TextColumn::make('fecha_emision')->label('Emisión')->dateTime('d/m/Y H:i')->sortable(), TextColumn::make('local_origen')->label('Origen')->wrap(),
            TextColumn::make('almacen')->label('Almacén')->toggleable(), TextColumn::make('local_destino')->label('Destino')->wrap(),
            TextColumn::make('total_items')->label('Ítems')->alignEnd(), TextColumn::make('total')->label('Total')->numeric(2)->alignEnd(),
            TextColumn::make('estado')->label('Estado')->badge()->color(fn (GuiaInterna $record) => $record->estado_codigo === '1' ? 'success' : 'gray'),
        ])->recordActions([
            ActionGroup::make([
            Action::make('detalle')->label('Ver guía interna')->icon('heroicon-o-eye')->visible(fn () => auth()->user()?->hasPermission('guias-internas.ver-detalle'))
                ->modalHeading(fn (GuiaInterna $record) => 'Guía interna #'.$record->restaurant_id)->modalWidth('7xl')->modalAlignment(Alignment::Start)
                ->modalSubmitAction(false)->modalCancelActionLabel('Cerrar')->stickyModalHeader()->stickyModalFooter()->schema([
                    Section::make()->schema([
                        TextEntry::make('serie')->label('Serie')->placeholder('—'), TextEntry::make('correlativo')->label('Número')->placeholder('—'),
                        TextEntry::make('fecha_emision')->label('Emisión')->dateTime('d/m/Y H:i')->placeholder('—'), TextEntry::make('fecha_traslado')->label('Traslado')->dateTime('d/m/Y H:i')->placeholder('—'),
                        TextEntry::make('local_origen')->label('Origen')->placeholder('—'), TextEntry::make('local_destino')->label('Destino')->placeholder('—'),
                        TextEntry::make('direccion_destino')->label('Dirección')->columnSpan(2)->placeholder('—')->wrap(), TextEntry::make('almacen')->label('Almacén')->placeholder('—'),
                        TextEntry::make('estado')->label('Estado')->badge()->placeholder('—'), TextEntry::make('observacion')->label('Observación')->columnSpanFull()->placeholder('—')->wrap(),
                    ])->columns(4),
                    Section::make('Ítems')->schema([
                        RepeatableEntry::make('detalles')->label('')->table([
                            TableColumn::make('Código'), TableColumn::make('Ítem'), TableColumn::make('Categoría'), TableColumn::make('Presentación'),
                            TableColumn::make('Cantidad')->alignEnd(), TableColumn::make('Salida')->alignEnd(), TableColumn::make('Stock')->alignEnd(), TableColumn::make('Peso')->alignEnd(),
                            TableColumn::make('Unidad'), TableColumn::make('Almacén'), TableColumn::make('Total')->alignEnd(),
                        ])->schema([
                            TextEntry::make('item_codigo')->placeholder('—'), TextEntry::make('item')->placeholder('—')->wrap(), TextEntry::make('categoria')->placeholder('—')->wrap(),
                            TextEntry::make('presentacion')->placeholder('—'), TextEntry::make('cantidad')->numeric(decimalPlaces: 3)->alignEnd(),
                            TextEntry::make('cantidad_salida')->numeric(decimalPlaces: 3)->alignEnd(), TextEntry::make('stock')->placeholder('—')->alignEnd(),
                            TextEntry::make('peso')->numeric(decimalPlaces: 3)->alignEnd(), TextEntry::make('unidad')->placeholder('—'),
                            TextEntry::make('almacen')->placeholder('—'), TextEntry::make('total')->numeric(decimalPlaces: 2)->alignEnd(),
                        ])->contained(false),
                    ]),
                ]),
            Action::make('workflow')->label('Ver workflow')->icon('heroicon-o-share')->visible(fn () => auth()->user()?->hasPermission('guias-internas.workflow'))
                ->modalHeading(fn (GuiaInterna $record) => 'Workflow de guía interna #'.$record->restaurant_id)
                ->modalWidth('5xl')->stickyModalHeader()->stickyModalFooter()
                ->modalSubmitAction(false)->modalCancelActionLabel('Cerrar')->schema([
                    Section::make('Documento y relaciones')->schema([
                        TextEntry::make('restaurant_id')->label('Guía interna'), TextEntry::make('estado')->label('Estado')->badge(),
                        TextEntry::make('requerimiento_restaurant_id')->label('Requerimiento vinculado')->placeholder('Sin vínculo'),
                        TextEntry::make('movimiento_restaurant_id')->label('Movimiento interno')->placeholder('Sin vínculo'),
                    ])->columns(['default' => 1, 'md' => 4]),
                ]),
            Action::make('anular')->label('Anular guía interna')->icon('heroicon-o-no-symbol')->color('danger')
                ->visible(fn (GuiaInterna $record) => $record->estado_codigo === '1' && (bool) auth()->user()?->hasPermission('guias-internas.anular'))
                ->requiresConfirmation()->modalHeading('¿Anular esta guía interna?')->modalDescription('Esta operación se realizará en Restaurant y no se puede deshacer.')
                ->modalWidth('lg')->stickyModalHeader()->stickyModalFooter()
                ->schema([Checkbox::make('devolver_cantidades')->label('Devolver las cantidades adquiridas')->default(true)])
                ->action(fn (GuiaInterna $record, array $data) => $this->anularGuia($record, (bool) ($data['devolver_cantidades'] ?? true))),
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
                        ->url(fn (Collection $records): string => 'https://corporaciondimsum.restaurant.pe/restaurant/logistica.html#!/movimientoalmacen/canjeguias/'.implode(',', $records->pluck('restaurant_id')->filter()->all()))
                        ->openUrlInNewTab(),
                    BulkAction::make('agrupar_guias')
                        ->label('Agrupar selección')
                        ->icon('heroicon-o-rectangle-stack')
                        ->requiresConfirmation()
                        ->modalHeading('Agrupar guías internas')
                        ->modalDescription('Restaurant creará una nueva guía consolidada y esta operación no se puede revertir.')
                        ->modalSubmitActionLabel('Agrupar')
                        ->action(function (Collection $records): void {
                            $ids = $records->pluck('restaurant_id')->filter()->map(fn ($id): string => (string) $id)->values()->all();
                            app(GuiasInternasGatewayClient::class)->agrupar($ids);
                            Notification::make()->success()->title('Guías agrupadas')->body('Restaurant confirmó la agrupación. Actualiza la lista para ver la nueva guía.')->send();
                            $this->resetTable();
                        }),
                ])->label('Operaciones de selección')->icon('heroicon-o-cog-6-tooth'),
            ])
            ->paginated([10, 25, 50, 100])->defaultPaginationPageOption(25)->emptyStateHeading('No hay guías internas en la copia local.');
    }

    public function locales(): array { return $this->scopeKeyedLocalsToUser(GuiaInterna::query()->whereNotNull('local_origen_id')->orderBy('local_origen')->pluck('local_origen', 'local_origen_id')->all()); }

    private function query(): Builder
    {
        $dateColumn = in_array($this->fechaTipo, ['fecha_emision', 'fecha_registro', 'fecha_traslado'], true) ? $this->fechaTipo : 'fecha_emision';
        $query = GuiaInterna::query()->when($this->desde, fn ($query) => $query->whereDate($dateColumn, '>=', $this->desde))
            ->when($this->hasta, fn ($query) => $query->whereDate($dateColumn, '<=', $this->hasta))
            ->when($this->localesOrigen !== [], fn ($query) => $query->whereIn($this->buscarSegun === '2' ? 'local_destino_id' : 'local_origen_id', $this->localesOrigen))
            ->when($this->almacen, fn ($query) => $query->where('almacen_id', $this->almacen))
            ->when($this->serie, fn ($query) => $query->where('serie', 'like', '%'.$this->serie.'%'))
            ->when($this->numero, fn ($query) => $query->where('correlativo', 'like', '%'.$this->numero.'%'))
            ->when($this->codigo, fn ($query) => $query->where('restaurant_id', 'like', '%'.$this->codigo.'%'))
            ->when($this->motivo, fn ($query) => $query->where('motivo_id', $this->motivo))
            ->when($this->items !== [], fn ($query) => $query->whereIn('id', GuiaInternaDetalle::query()->whereIn('item_id', $this->items)->select('guia_interna_id')))
            ->when($this->estado !== null && $this->estado !== '', fn ($query) => $query->where('estado_codigo', $this->estado));
        if (auth()->user()?->isRestrictedToLocals()) $query->whereIn('local_origen_id', auth()->user()->assignedLocalIds());
        return $query->orderByDesc('fecha_emision')->orderByDesc('id');
    }

    /** @return array<string, string> */
    private function almacenOptions(): array
    {
        $query = GuiaInterna::query()->whereNotNull('almacen_id')->whereNotNull('almacen');
        if (auth()->user()?->isRestrictedToLocals()) $query->whereIn('local_origen_id', auth()->user()->assignedLocalIds());
        if ($this->localesOrigen !== []) $query->whereIn('local_origen_id', $this->localesOrigen);

        return $query->orderBy('almacen')->pluck('almacen', 'almacen_id')->all();
    }

    /** @return array<string, string> */
    private function motivoOptions(): array
    {
        $query = GuiaInterna::query()->whereNotNull('motivo_id')->whereNotNull('motivo');
        if (auth()->user()?->isRestrictedToLocals()) $query->whereIn('local_origen_id', auth()->user()->assignedLocalIds());

        return $query->orderBy('motivo')->pluck('motivo', 'motivo_id')->all();
    }

    /** @return array<string, string> */
    private function itemOptions(string $search): array
    {
        $search = trim($search);
        if (mb_strlen($search) < 2) return [];

        $guideIds = GuiaInterna::query()->select('id');
        if (auth()->user()?->isRestrictedToLocals()) $guideIds->whereIn('local_origen_id', auth()->user()->assignedLocalIds());

        return GuiaInternaDetalle::query()->whereIn('guia_interna_id', $guideIds)
            ->where(fn ($query) => $query->where('item_codigo', 'ilike', '%'.$search.'%')->orWhere('item', 'ilike', '%'.$search.'%'))
            ->orderBy('item')->limit(20)->get(['item_id', 'item_codigo', 'item'])
            ->mapWithKeys(fn (GuiaInternaDetalle $item): array => [(string) $item->item_id => trim(($item->item_codigo ? $item->item_codigo.' · ' : '').$item->item)])
            ->all();
    }

    /** @param array<int, string> $values @return array<string, string> */
    private function itemLabels(array $values): array
    {
        if ($values === []) return [];

        $guideIds = GuiaInterna::query()->select('id');
        if (auth()->user()?->isRestrictedToLocals()) $guideIds->whereIn('local_origen_id', auth()->user()->assignedLocalIds());

        return GuiaInternaDetalle::query()->whereIn('guia_interna_id', $guideIds)->whereIn('item_id', $values)
            ->orderBy('item')->get(['item_id', 'item_codigo', 'item'])->unique('item_id')
            ->mapWithKeys(fn (GuiaInternaDetalle $item): array => [(string) $item->item_id => trim(($item->item_codigo ? $item->item_codigo.' · ' : '').$item->item)])
            ->all();
    }

    private function filterText(mixed $value, int $maxLength): ?string
    {
        $value = mb_substr(trim((string) $value), 0, $maxLength);

        return $value !== '' ? $value : null;
    }

    private function downloadAction(string $variant, string $label): Action
    {
        return Action::make('descargar_'.$variant)->label($label)->icon('heroicon-o-arrow-down-tray')
            ->action(fn (GuiaInterna $record) => $this->descargarGuia($record, $variant));
    }

    public function descargarGuia(GuiaInterna $record, string $variant): mixed
    {
        $reporte = app(GuiasInternasGatewayClient::class)->reporte((string) $record->restaurant_id, $variant);
        $extension = $variant === 'csv' ? 'csv' : 'pdf';
        return response()->streamDownload(fn () => print($reporte['content']), 'guia-interna-'.$record->serie.'-'.$record->correlativo.'.'.$extension, ['Content-Type' => $reporte['contentType']]);
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
        ];
    }

    public function anularGuia(GuiaInterna $record, bool $devolverCantidades): void
    {
        app(GuiasInternasGatewayClient::class)->anular((string) $record->restaurant_id, $devolverCantidades);
        $record->update(['estado_codigo' => '0', 'estado' => 'Anulado', 'sincronizado_en' => now()]);
        Notification::make()->success()->title('Guía interna anulada')->body('Restaurant confirmó la anulación. La copia local se actualizó.')->send();
        $this->resetTable();
    }
}
