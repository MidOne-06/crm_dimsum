<?php

namespace App\Filament\Pages\RequerimientosStock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Services\RequerimientoStockGatewayClient;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImportarPlantilla extends Page implements HasTable
{
    use InteractsWithTable {
        InteractsWithTable::updatedTableFilters as protected filamentUpdatedTableFilters;
        InteractsWithTable::applyTableFilters as protected filamentApplyTableFilters;
    }
    use ScopesLocalsToUser;

    private const TODOS_LOCALES = '-1';
    private const MIS_LOCALES = '__mis_locales__';
    private const GATEWAY_PAGE_SIZE = 100;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-arrow-down';
    protected static ?string $navigationLabel = 'Importar plantilla';
    protected static ?string $title = 'Importar plantillas de requerimiento';
    protected static string|\UnitEnum|null $navigationGroup = 'Requerimientos de Stock';
    protected static ?int $navigationSort = 15;
    protected static ?string $slug = 'requerimientos-stock/importar-plantilla';
    protected string $view = 'filament.pages.requerimientos-stock.importar-plantilla';

    /** @var array<string, string> */
    public array $localOptions = [];

    public ?string $loadError = null;
    public ?string $plantillasLocalId = null;
    public string $plantillasLocalNombre = '';

    /** @var array<int, array<string, mixed>> */
    public array $plantillasDelLocal = [];

    /** @var array<string, mixed>|null */
    public ?array $plantillaVistaPrevia = null;

    /** @var array<string, mixed>|null */
    public ?array $plantillaPendienteImportacion = null;

    public bool $incluirCantidadesCero = true;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        // Terminal imports from Nuevo requerimiento, where its authorized
        // templates are already filtered by assigned local. Keeping this page
        // visible would duplicate the same action and create two workflows.
        return (bool) $user?->hasPermission('requerimientos-stock.plantillas.view')
            && ! $user->roles()->where('slug', 'terminal')->exists();
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

    public function updatedTableFilters(): void
    {
        $this->cachedTableRecords = null;
        $this->filamentUpdatedTableFilters();
    }

    public function applyTableFilters(): void
    {
        $this->cachedTableRecords = null;
        $this->filamentApplyTableFilters();
    }

    public function abrirPlantillasLocal(string $localId): void
    {
        if (! $this->localAllowedForUser($localId)) {
            Notification::make()->title('No tienes acceso a este local.')->danger()->send();

            return;
        }

        try {
            $this->loadError = null;
            $this->plantillasDelLocal = $this->normalizeRows($this->allRowsForLocal($localId))->values()->all();
            $this->plantillasLocalId = $localId;
            $this->plantillasLocalNombre = (string) ($this->localOptions[$localId] ?? 'Local');
            $this->dispatch('open-modal', id: 'plantillas-del-local');
        } catch (Throwable $exception) {
            $this->loadError = $this->friendlyError($exception);
        }
    }

    public function verPlantillaDelLocal(string $templateId): void
    {
        $plantilla = $this->plantillaDelLocal($templateId);
        if ($plantilla === null) {
            Notification::make()->title('La plantilla ya no está disponible.')->danger()->send();

            return;
        }

        $this->plantillaVistaPrevia = $plantilla;
        $this->dispatch('open-modal', id: 'vista-previa-plantilla');
    }

    public function abrirImportacionPlantilla(string $templateId): void
    {
        if (! auth()->user()?->hasPermission('requerimientos-stock.plantillas.importar')) {
            Notification::make()->title('No tienes permiso para importar plantillas.')->danger()->send();

            return;
        }

        $plantilla = $this->plantillaDelLocal($templateId);
        if ($plantilla === null) {
            Notification::make()->title('La plantilla ya no está disponible.')->danger()->send();

            return;
        }

        $this->plantillaPendienteImportacion = $plantilla;
        $this->incluirCantidadesCero = true;
        $this->dispatch('open-modal', id: 'confirmar-importacion-plantilla');
    }

    public function confirmarImportacionPlantilla(): void
    {
        if (! is_array($this->plantillaPendienteImportacion)) {
            Notification::make()->title('Selecciona una plantilla válida.')->danger()->send();

            return;
        }

        $this->importar($this->plantillaPendienteImportacion, $this->incluirCantidadesCero);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (int $page, int $recordsPerPage): LengthAwarePaginator => $this->records($this->tableFilters, $page, $recordsPerPage))
            ->columns([
                TextColumn::make('id')->label('Cód.')->weight('medium'),
                TextColumn::make('nombre')->label('Nombre')->searchable()->wrap(),
                TextColumn::make('encargado')->label('Encargado')->toggleable()->wrap(),
                TextColumn::make('receptor')->label('Receptor')->toggleable(isToggledHiddenByDefault: true)->wrap(),
                TextColumn::make('local_origen')->label('Solicitado por')->toggleable()->wrap(),
                TextColumn::make('local_produccion')->label('Local de producción')->toggleable()->wrap(),
                TextColumn::make('items_count')->label('Ítems')->alignEnd(),
            ])
            ->filters([
                Filter::make('local')->label('Filtros de plantillas')->schema([
                    Select::make('local_id')
                        ->label('Local')
                        ->native(false)
                        ->searchable()
                        ->options(fn (): array => $this->localSelectOptions())
                        ->default(fn (): string => $this->defaultLocalFilter()),
                ]),
            ], layout: FiltersLayout::Modal)
            ->filtersFormColumns(1)
            ->filtersFormWidth('5xl')
            ->filtersTriggerAction(fn (Action $action): Action => $action
                ->label('Filtros')
                ->icon('heroicon-o-adjustments-horizontal')
                ->modalHeading('Filtros de plantillas')
                ->modalSubmitActionLabel('Aplicar filtros')
                ->modalCancelActionLabel('Cancelar'))
            ->deferFilters()
            ->recordActions([
                Action::make('ver')
                    ->label('Ver')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (array $record): string => 'Plantilla #'.($record['id'] ?? ''))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar')
                    ->modalWidth('7xl')
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->modalContent(fn (array $record) => view('filament.pages.requerimientos-stock.plantilla-modal', ['plantilla' => $record])),
                Action::make('importarPlantilla')
                    ->label('Importar')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('primary')
                    ->visible(fn (): bool => (bool) auth()->user()?->hasPermission('requerimientos-stock.plantillas.importar'))
                    ->modal()
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record): string => 'Importar plantilla #'.($record['id'] ?? ''))
                    ->modalWidth('lg')
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->modalSubmitActionLabel('Importar')
                    ->modalCancelActionLabel('Cancelar')
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 2, 'xl' => 4])
                            ->schema([
                                Toggle::make('incluir_cantidades_cero')
                                    ->label('Incluir cantidades cero')
                                    ->default(true)
                                    ->columnSpanFull(),
                            ]),
                    ])
                    ->action(fn (array $record, array $data) => $this->importar($record, (bool) ($data['incluir_cantidades_cero'] ?? true))),
            ])
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(10)
            // Sin poll(): cada refresco vuelve a traer TODAS las páginas de
            // plantillas de Restaurant para cada local asignado del usuario
            // (ver allRowsForLocal/assignedLocalRecords) -- con varias
            // pestañas abiertas eso compite innecesariamente por el mismo
            // pool de sesiones de Restaurant.pe que el resto del sistema ya
            // administra con cuidado. La tabla se refresca sola al filtrar o
            // paginar; no hace falta un poll automático de fondo.
            ->emptyStateHeading('Sin plantillas');
    }

    /** @param array<string, mixed> $filters */
    protected function records(array $filters, int $page, int $recordsPerPage): LengthAwarePaginator
    {
        $localId = (string) ($filters['local_id'] ?? $filters['local']['local_id'] ?? self::TODOS_LOCALES);

        if ($localId === self::MIS_LOCALES) {
            if (! $this->isRestrictedToLocals()) {
                $localId = self::TODOS_LOCALES;
            } else {
                return $this->assignedLocalRecords($page, $recordsPerPage);
            }
        }

        // Restaurant uses -1 as an unrestricted query. It must never be sent by
        // an account whose scope is limited to assigned locations.
        if ($localId === self::TODOS_LOCALES && $this->isRestrictedToLocals()) {
            return $this->emptyPaginator($page, $recordsPerPage);
        }

        if ($localId !== self::TODOS_LOCALES && ! $this->localAllowedForUser($localId)) {
            return $this->emptyPaginator($page, $recordsPerPage);
        }

        try {
            $this->loadError = null;
            $result = $this->gateway()->plantillas($localId, $page, $recordsPerPage);
            $rows = $this->normalizeRows(collect($result['rows'] ?? []));

            return $this->paginator($rows, (int) ($result['total'] ?? $rows->count()), $page, $recordsPerPage);
        } catch (Throwable $exception) {
            $this->loadError = $this->friendlyError($exception);

            return $this->emptyPaginator($page, $recordsPerPage);
        }
    }

    /** @return array<string, mixed>|null */
    private function plantillaDelLocal(string $templateId): ?array
    {
        $plantilla = collect($this->plantillasDelLocal)
            ->first(fn (array $row): bool => (string) ($row['id'] ?? '') === $templateId);

        if (! is_array($plantilla)) {
            return null;
        }

        return $this->localAllowedForUser((string) ($plantilla['local_origen_id'] ?? '')) ? $plantilla : null;
    }

    /** @param array<string, mixed> $plantilla */
    public function importar(array $plantilla, bool $incluirCantidadesCero): void
    {
        if (! auth()->user()?->hasPermission('requerimientos-stock.plantillas.importar')) {
            Notification::make()->title('No tienes permiso para importar plantillas.')->danger()->send();

            return;
        }

        $templateId = (string) ($plantilla['id'] ?? '');
        $localId = (string) ($plantilla['local_origen_id'] ?? '');
        if ($templateId === '' || ! $this->localAllowedForUser($localId)) {
            Notification::make()->title('La plantilla no está disponible para tu usuario.')->danger()->send();

            return;
        }

        try {
            $importada = $this->gateway()->importarPlantilla($templateId, $incluirCantidadesCero);
            $remoteLocalId = (string) ($importada['localOrigenId'] ?? '');
            if ($remoteLocalId === '' || ! $this->localAllowedForUser($remoteLocalId)) {
                Log::warning('[ImportarPlantillaRequerimiento] Restaurant returned a template outside the user scope.', [
                    'template_id' => $templateId,
                    'local_id' => $remoteLocalId,
                    'user_id' => auth()->id(),
                ]);
                Notification::make()->title('La plantilla no está disponible para tu usuario.')->danger()->send();

                return;
            }

            if (empty($importada['items'])) {
                Notification::make()->title('La plantilla no contiene ítems.')->warning()->send();

                return;
            }

            // Se conserva el identificador: Restaurant lo exige si el usuario
            // autorizado decide actualizar la misma plantilla al guardar.
            $importada['nombre'] = (string) ($plantilla['nombre'] ?? '');
            session(['requerimientos-stock.plantilla-importada' => $importada]);
            $this->redirect(NuevoRequerimiento::getUrl());
        } catch (Throwable $exception) {
            $this->loadError = $this->friendlyError($exception);
        }
    }

    /** @return array<string, string> */
    private function localSelectOptions(): array
    {
        if ($this->isRestrictedToLocals()) {
            // A restricted user never receives Restaurant's unrestricted option.
            // With multiple assignments this virtual option merges only those locals.
            if (count($this->localOptions) > 1) {
                return [self::MIS_LOCALES => 'Mis locales asignados'] + $this->localOptions;
            }

            return $this->localOptions;
        }

        return [self::TODOS_LOCALES => 'Todos los locales'] + $this->localOptions;
    }

    private function defaultLocalFilter(): string
    {
        if (! $this->isRestrictedToLocals()) {
            return self::TODOS_LOCALES;
        }

        $localIds = array_keys($this->localOptions);

        return count($localIds) === 1 ? (string) $localIds[0] : self::MIS_LOCALES;
    }

    private function isRestrictedToLocals(): bool
    {
        return (bool) auth()->user()?->isRestrictedToLocals();
    }

    private function assignedLocalRecords(int $page, int $recordsPerPage): LengthAwarePaginator
    {
        if ($this->localOptions === []) {
            return $this->emptyPaginator($page, $recordsPerPage);
        }

        try {
            $this->loadError = null;
            $rows = collect();

            foreach (array_keys($this->localOptions) as $localId) {
                $rows = $rows->concat($this->allRowsForLocal((string) $localId));
            }

            $rows = $this->normalizeRows($rows)
                ->unique(fn (array $row): string => (string) ($row['local_origen_id'] ?? '').'|'.(string) ($row['id'] ?? ''))
                ->sortByDesc(fn (array $row): int => (int) ($row['id'] ?? 0))
                ->values();

            $total = $rows->count();

            return $this->paginator($rows->forPage($page, $recordsPerPage)->values(), $total, $page, $recordsPerPage);
        } catch (Throwable $exception) {
            $this->loadError = $this->friendlyError($exception);

            return $this->emptyPaginator($page, $recordsPerPage);
        }
    }

    /** @return Collection<int, array<string, mixed>> */
    private function allRowsForLocal(string $localId): Collection
    {
        $firstPage = $this->gateway()->plantillas($localId, 1, self::GATEWAY_PAGE_SIZE);
        $rows = collect($firstPage['rows'] ?? []);
        $total = (int) ($firstPage['total'] ?? $rows->count());
        $pages = max(1, (int) ceil($total / self::GATEWAY_PAGE_SIZE));

        for ($currentPage = 2; $currentPage <= $pages; $currentPage++) {
            $result = $this->gateway()->plantillas($localId, $currentPage, self::GATEWAY_PAGE_SIZE);
            $rows = $rows->concat($result['rows'] ?? []);
        }

        return $rows;
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function normalizeRows(Collection $rows): Collection
    {
        return $rows->map(function (array $row): array {
            $row['items_count'] = count($row['recetas'] ?? []) + count($row['insumos'] ?? []) + count($row['productos'] ?? []);

            return $row;
        });
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function paginator(Collection $rows, int $total, int $page, int $recordsPerPage): LengthAwarePaginator
    {
        return new LengthAwarePaginator($rows, $total, $recordsPerPage, $page, [
            'path' => request()->url(),
            'pageName' => 'plantillasPage',
        ]);
    }

    private function emptyPaginator(int $page, int $recordsPerPage): LengthAwarePaginator
    {
        return $this->paginator(collect(), 0, $page, $recordsPerPage);
    }

    private function gateway(): RequerimientoStockGatewayClient
    {
        return app(RequerimientoStockGatewayClient::class);
    }

    private function friendlyError(Throwable $exception): string
    {
        Log::error('[ImportarPlantillaRequerimiento] '.$exception->getMessage(), ['exception' => $exception]);

        return 'No se pudieron cargar las plantillas desde Restaurant.';
    }
}
