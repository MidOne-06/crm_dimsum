<?php

namespace App\Filament\Pages\RequerimientosStock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Services\RequerimientoStockGatewayClient;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Throwable;

class ImportarPlantilla extends Page implements HasTable
{
    use InteractsWithTable {
        InteractsWithTable::updatedTableFilters as protected filamentUpdatedTableFilters;
        InteractsWithTable::applyTableFilters as protected filamentApplyTableFilters;
    }
    use ScopesLocalsToUser;

    private const TODOS_LOCALES = '-1';

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

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('requerimientos-stock.plantillas.view');
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
                Filter::make('local')->label('Filtros')->schema([
                    Select::make('local_id')
                        ->label('Local')
                        ->native(false)
                        ->searchable()
                        ->options($this->localSelectOptions())
                        ->default(self::TODOS_LOCALES),
                ]),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(1)
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
                    ->modalSubmitActionLabel('Importar')
                    ->modalCancelActionLabel('Cancelar')
                    ->modalContent(new HtmlString(''))
                    ->schema([
                        Toggle::make('incluir_cantidades_cero')->label('Incluir cantidades cero')->default(true),
                    ])
                    ->action(fn (array $record, array $data) => $this->importar($record, (bool) ($data['incluir_cantidades_cero'] ?? true))),
            ])
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(10)
            ->poll('60s')
            ->emptyStateHeading('Sin plantillas');
    }

    /** @param array<string, mixed> $filters */
    protected function records(array $filters, int $page, int $recordsPerPage): LengthAwarePaginator
    {
        $localId = (string) ($filters['local_id'] ?? $filters['local']['local_id'] ?? self::TODOS_LOCALES);
        if ($localId !== self::TODOS_LOCALES && ! $this->localAllowedForUser($localId)) {
            return new LengthAwarePaginator(collect(), 0, $recordsPerPage, $page);
        }

        try {
            $this->loadError = null;
            $result = $this->gateway()->plantillas($localId, $page, $recordsPerPage);
            $rows = collect($result['rows'] ?? [])->map(function (array $row): array {
                $row['items_count'] = count($row['recetas'] ?? []) + count($row['insumos'] ?? []) + count($row['productos'] ?? []);

                return $row;
            });

            return new LengthAwarePaginator($rows, (int) ($result['total'] ?? $rows->count()), $recordsPerPage, $page, [
                'path' => request()->url(),
                'pageName' => 'plantillasPage',
            ]);
        } catch (Throwable $exception) {
            $this->loadError = $this->friendlyError($exception);

            return new LengthAwarePaginator(collect(), 0, $recordsPerPage, $page);
        }
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
            if (empty($importada['items'])) {
                Notification::make()->title('La plantilla no contiene ítems.')->warning()->send();

                return;
            }

            session(['requerimientos-stock.plantilla-importada' => $importada]);
            $this->redirect(NuevoRequerimiento::getUrl());
        } catch (Throwable $exception) {
            $this->loadError = $this->friendlyError($exception);
        }
    }

    /** @return array<string, string> */
    private function localSelectOptions(): array
    {
        return [self::TODOS_LOCALES => 'Todos los locales'] + $this->localOptions;
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
