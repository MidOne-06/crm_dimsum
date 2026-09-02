<?php

namespace App\Filament\Pages\RequerimientosStock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Filament\Pages\Stock\NuevaGuiaInterna;
use App\Services\RequerimientoStockGatewayClient;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Filament\Support\Enums\Alignment;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class ListaRequerimientos extends Page implements HasTable
{
    use InteractsWithTable;
    use ScopesLocalsToUser;

    private const ALL_LOCALES_OPTION = '__all_locales__';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationLabel = 'Requerimientos';
    protected static ?string $title = 'Requerimientos de Stock';
    protected static string|\UnitEnum|null $navigationGroup = 'Requerimientos de Stock';
    protected static ?int $navigationSort = 5;
    protected static ?string $slug = 'requerimientos-stock';
    protected string $view = 'filament.pages.requerimientos-stock.lista';

    /** @var array<string, string> */
    public array $localOptions = [];
    public ?string $loadError = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('requerimientos-stock.view');
    }

    public function mount(): void
    {
        try {
            $this->localOptions = collect($this->scopeLocalsToUser($this->gateway()->locals()))
                ->mapWithKeys(fn (array $local): array => [(string) $local['id'] => (string) $local['name']])
                ->all();
        } catch (Throwable $exception) {
            $this->loadError = $this->friendlyError($exception);
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (array $filters, int $page, int $recordsPerPage): LengthAwarePaginator => $this->records($filters, $page, $recordsPerPage))
            ->columns([
                TextColumn::make('codigo')->label('Cód.')->weight('medium'),
                TextColumn::make('fecha_registro')->label('Fecha de registro')->wrap(),
                TextColumn::make('fecha_abastecimiento')->label('Abastecimiento')->wrap(),
                TextColumn::make('solicitado_por')->label('Solicitado por')->wrap()->toggleable(),
                TextColumn::make('local_produccion')->label('Local de producción')->wrap()->toggleable(),
                TextColumn::make('encargado')->label('Encargado')->wrap()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('receptor')->label('Receptor')->wrap()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('movimiento')->label('Movimiento')->wrap()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('proceso_produccion')->label('Proceso de producción')->wrap()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('otros_documentos')->label('Documentos vinculados')->wrap()->toggleable(),
                TextColumn::make('estado_despacho')->label('Despacho')->badge()->toggleable(),
                TextColumn::make('estado')->label('Estado')->badge()->color(fn (?string $state): string => match (mb_strtolower((string) $state)) {
                    'completo', 'recibido', 'aprobado' => 'success', 'anulado', 'rechazado' => 'danger', default => 'warning',
                }),
                TextColumn::make('fecha_aprobacion')->label('Fecha de aprobación')->wrap()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('ver')
                      ->label('Ver')
                      ->icon('heroicon-o-eye')
                      ->visible(fn (): bool => (bool) auth()->user()?->hasPermission('requerimientos-stock.ver-detalle'))
                    ->modalHeading(fn (array $record): string => 'Requerimiento #'.($record['codigo'] ?? ''))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar')
                    ->modalAlignment(Alignment::Start)
                    ->modalWidth('7xl')
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->modalContent(fn (array $record) => view('filament.pages.requerimientos-stock.detalle-modal', [
                        'erpId' => (string) ($record['codigo'] ?? ''),
                    ])),
                    Action::make('aprobar')
                    ->label('Aprobar')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (array $record): bool => $this->canApprove($record) && (bool) auth()->user()?->hasPermission('requerimientos-stock.aprobar'))
                    ->action(fn (array $record) => $this->runRemoteAction('aprobar', $record)),
                    Action::make('rechazar')
                    ->label('Rechazar')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalWidth('5xl')
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->schema([Textarea::make('motivo')->label('Motivo')->required()->maxLength(80)->rows(3)])
                    ->visible(fn (array $record): bool => $this->canReject($record) && (bool) auth()->user()?->hasPermission('requerimientos-stock.rechazar'))
                    ->action(fn (array $record, array $data) => $this->runRemoteAction('rechazar', $record, (string) ($data['motivo'] ?? ''))),
                    Action::make('anular')
                    ->label('Anular')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (array $record): bool => $this->canCancel($record) && (bool) auth()->user()?->hasPermission('requerimientos-stock.anular'))
                    ->action(fn (array $record) => $this->runRemoteAction('anular', $record)),
                    Action::make('generarGuiaInterna')
                    ->label('Generar guía interna')
                    ->icon('heroicon-o-document-plus')
                    ->color('primary')
                    ->visible(fn (array $record): bool => mb_strtolower((string) ($record['estado'] ?? '')) === 'aprobado' && (bool) auth()->user()?->hasPermission('guias-internas.crear'))
                    ->url(fn (array $record): string => NuevaGuiaInterna::getUrl(['requerimiento' => (string) ($record['codigo'] ?? '')])),
                ])
                    ->icon('heroicon-o-cog-6-tooth')
                    ->tooltip('Operaciones')
                    ->color('gray')
                    ->dropdownPlacement('bottom-end')
                    ->dropdownWidth('xs'),
            ])
            ->filters([
                Filter::make('criterios')->label('Filtros de búsqueda')->schema([
                    Grid::make(['default' => 1, 'md' => 2, 'xl' => 4])->schema([
                        Select::make('fecha_tipo')->label('Filtrar fecha por')->options(['0' => 'Fecha de registro', '1' => 'Fecha de abastecimiento'])->default('0')->native(false),
                        ViewField::make('fecha_rango')
                            ->view('filament.forms.components.requerimientos-date-range')
                            ->columnSpan(['xl' => 2]),
                        \Filament\Forms\Components\Hidden::make('fecha_inicio')->default(now()->toDateString()),
                        \Filament\Forms\Components\Hidden::make('fecha_fin')->default(now()->toDateString()),
                        Select::make('estado')->label('Estado')->options(['-1' => 'Todos', '0' => 'Anulado', '1' => 'Pendiente', '2' => 'Aprobado', '3' => 'Rechazado', '4' => 'Despachado', '5' => 'Recibido'])->default('-1')->native(false),
                        TextInput::make('codigo')->label('Código')->placeholder('Ej. 5926')->maxLength(40),
                        TextInput::make('encargado')->label('Encargado')->placeholder('Nombre del encargado')->maxLength(100),
                        Select::make('locales')->label('Solicitado por')->options($this->localSelectOptions())->multiple()->searchable()->native(false)->optionsLimit(10)->placeholder('Todos los locales'),
                        Select::make('locales_produccion')->label('Local de producción')->options($this->localSelectOptions())->multiple()->searchable()->native(false)->optionsLimit(10)->placeholder('Todos los locales'),
                        Select::make('items')->label('Contiene insumo o producto')->multiple()->searchable()->native(false)->optionsLimit(10)
                            ->getSearchResultsUsing(fn (string $search): array => $this->itemOptions($search))
                            ->getOptionLabelsUsing(fn (array $values): array => $this->itemLabels($values))
                            ->placeholder('Busca por nombre o código')->columnSpan(['md' => 2, 'xl' => 4]),
                    ]),
                ]),
            ], layout: FiltersLayout::Modal)
            ->filtersFormColumns(1)
            ->filtersFormWidth('5xl')
            ->filtersTriggerAction(fn (Action $action): Action => $action
                ->label('Filtros')
                ->icon('heroicon-o-adjustments-horizontal')
                ->modalHeading('Filtros de requerimientos')
                ->modalSubmitActionLabel('Aplicar filtros')
                ->modalCancelActionLabel('Cancelar'))
            ->deferFilters()
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('Sin registros');
    }

    /** @param array<string, mixed> $filters */
    protected function records(array $filters, int $page, int $recordsPerPage): LengthAwarePaginator
    {
        $criteria = (array) ($filters['criterios'] ?? []);

        try {
            $this->loadError = null;
            $result = $this->gateway()->lista([
                'pagina' => $page, 'registros' => $recordsPerPage,
                'fecha_inicio' => $this->dateValue($criteria['fecha_inicio'] ?? null),
                'fecha_fin' => $this->dateValue($criteria['fecha_fin'] ?? null),
                'locales' => $this->selectedLocalIds($criteria['locales'] ?? []),
                'locales_produccion' => $this->selectedLocalIds($criteria['locales_produccion'] ?? []),
                'estado' => $criteria['estado'] ?? '-1',
                'codigo' => trim((string) ($criteria['codigo'] ?? '')),
                'encargado' => trim((string) ($criteria['encargado'] ?? '')),
                'por_fecha' => $criteria['fecha_tipo'] ?? '0',
                'items' => $this->selectedItems($criteria['items'] ?? []),
            ]);

            return new LengthAwarePaginator(collect($result['rows'] ?? []), (int) ($result['total'] ?? 0), $recordsPerPage, $page, ['path' => request()->url(), 'pageName' => 'requerimientosPage']);
        } catch (Throwable $exception) {
            $this->loadError = $this->friendlyError($exception);
            return new LengthAwarePaginator(collect(), 0, $recordsPerPage, $page);
        }
    }

    /** @return array<string, string> */
    protected function localSelectOptions(): array
    {
        return [self::ALL_LOCALES_OPTION => 'Todos los locales'] + $this->localOptions;
    }

    /** @param array<int, mixed> $values @return array<int, string> */
    protected function selectedLocalIds(array $values): array
    {
        $values = array_values(array_filter($values, fn (mixed $value): bool => filled($value)));
        return in_array(self::ALL_LOCALES_OPTION, $values, true) ? array_keys($this->localOptions) : $this->safeLocalFilter($values);
    }

    /** @return array<string, string> */
    protected function itemOptions(string $search): array
    {
        if (mb_strlen(trim($search)) < 3) return [];
        return collect($this->gateway()->searchItems(trim($search)))->take(20)->mapWithKeys(fn (array $item): array => [
            ((string) $item['item_id']).':'.((string) $item['item_tipo']) => trim(($item['item_codigo'] ?? '').' · '.($item['item_descripcion'] ?? '')),
        ])->all();
    }

    /** @param array<int, mixed> $values @return array<string, string> */
    protected function itemLabels(array $values): array
    {
        return collect($values)->mapWithKeys(function (mixed $value): array {
            [$id, $type] = array_pad(explode(':', (string) $value, 2), 2, '');
            $item = collect($this->gateway()->searchItems($id))->first(fn (array $candidate): bool => (string) ($candidate['item_id'] ?? '') === $id && (string) ($candidate['item_tipo'] ?? '') === $type);
            return [(string) $value => $item ? trim(($item['item_codigo'] ?? '').' · '.($item['item_descripcion'] ?? '')) : (string) $value];
        })->all();
    }

    /** @param array<int, mixed> $values @return array<int, array{id: string, tipo: string}> */
    protected function selectedItems(array $values): array
    {
        return collect($values)->map(function (mixed $value): ?array {
            [$id, $type] = array_pad(explode(':', (string) $value, 2), 2, '');
            return ctype_digit($id) && ctype_digit($type) ? ['id' => $id, 'tipo' => $type] : null;
        })->filter()->take(5)->values()->all();
    }

    protected function dateValue(mixed $date): string { return filled($date) ? Carbon::parse($date)->toDateString() : now()->toDateString(); }
    private function gateway(): RequerimientoStockGatewayClient { return app(RequerimientoStockGatewayClient::class); }

    /** @param array<string, mixed> $record */
    protected function canApprove(array $record): bool
    {
        return in_array(mb_strtolower((string) ($record['estado'] ?? '')), ['pendiente', 'rechazado'], true);
    }

    /** @param array<string, mixed> $record */
    protected function canReject(array $record): bool
    {
        return in_array(mb_strtolower((string) ($record['estado'] ?? '')), ['pendiente', 'aprobado'], true);
    }

    /** @param array<string, mixed> $record */
    protected function canCancel(array $record): bool
    {
        return in_array(mb_strtolower((string) ($record['estado'] ?? '')), ['pendiente', 'rechazado'], true);
    }

    /** @param array<string, mixed> $record */
    protected function runRemoteAction(string $action, array $record, string $motivo = ''): void
    {
        $permission = 'requerimientos-stock.'.$action;
        if (! auth()->user()?->hasPermission($permission)) {
            Notification::make()->title('No tienes permiso para esta acción.')->danger()->send();

            return;
        }

        $id = (string) ($record['codigo'] ?? '');
        $gateway = $this->gateway();

        match ($action) {
            'aprobar' => $gateway->aprobar($id),
            'rechazar' => $gateway->rechazar($id, $motivo),
            'anular' => $gateway->anular($id),
        };

        app(\App\Services\RequerimientoStockHistoricoService::class)->sincronizar($gateway->detalle($id));
        Notification::make()->success()->title('Actualizado')->send();
        $this->resetTable();
    }

    public function syncRequirementDateRange(string $start, string $end): void
    {
        $start = Carbon::parse($start)->toDateString();
        $end = Carbon::parse($end)->toDateString();
        if ($start > $end) [$start, $end] = [$end, $start];

        foreach (['tableFilters', 'tableDeferredFilters'] as $property) {
            $this->{$property} ??= [];
            $this->{$property}['criterios'] ??= [];
            $this->{$property}['criterios']['fecha_inicio'] = $start;
            $this->{$property}['criterios']['fecha_fin'] = $end;
        }
    }

    /** @param array<int, mixed> $localIds @return array<int, string> */
    private function safeLocalFilter(array $localIds): array
    {
        $allowed = $this->restrictLocalIdsToUser($localIds);
        return ($allowed !== [] || ! auth()->user()?->isRestrictedToLocals()) ? $allowed : array_keys($this->localOptions);
    }

    private function friendlyError(Throwable $exception): string
    {
        Log::error('[ListaRequerimientosStock] '.$exception->getMessage(), ['exception' => $exception]);

        if (str_contains($exception->getMessage(), 'Failed to fetch') || str_contains($exception->getMessage(), 'Restaurant respondió')) {
            return 'No se pudo actualizar la lista desde Restaurant. Intenta nuevamente en unos minutos.';
        }

        return 'No se pudo cargar la lista: '.$exception->getMessage();
    }
}
