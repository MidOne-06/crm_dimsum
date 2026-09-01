<?php

namespace App\Filament\Pages\Stock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Models\SalidaStock;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SalidasStock extends Page implements HasTable
{
    use InteractsWithTable;
    use ScopesLocalsToUser;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';
    protected static ?string $navigationLabel = 'Salidas de stock';
    protected static ?string $title = 'Salidas de stock';
    protected static string|\UnitEnum|null $navigationGroup = 'Stock Actual';
    protected static ?int $navigationSort = 13;
    protected string $view = 'filament.pages.stock.salidas-stock';

    public string $desde = '';
    public string $hasta = '';
    public string $activeDatePreset = 'last30';
    public ?string $local = null;
    public ?string $categoria = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('salidas-stock.view');
    }

    public function mount(): void
    {
        $this->desde = now()->subDays(30)->toDateString();
        $this->hasta = now()->toDateString();
    }

    public function setDateRange(string $start, string $end, ?string $preset = 'custom'): void
    {
        $this->desde = $start;
        $this->hasta = $end;
        $this->activeDatePreset = $preset ?: 'custom';
        $this->resetTable();
    }

    public function aplicar(): void
    {
        $this->resetTable();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('nuevaSalida')
                ->label('Nueva salida')
                ->icon('heroicon-o-plus')
                ->visible(fn (): bool => (bool) auth()->user()?->hasPermission('salidas-stock.crear'))
                ->url(NuevaSalidaStock::getUrl()),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->query())
            ->columns([
                TextColumn::make('restaurant_id')->label('Cód.')->sortable(),
                TextColumn::make('fecha')->date('d/m/Y')->sortable(),
                TextColumn::make('hora')->label('Hora'),
                TextColumn::make('local_nombre')->label('Local')->searchable()->wrap(),
                TextColumn::make('categoria')->label('Categoría')->badge(),
                TextColumn::make('responsable')->label('Responsable')->toggleable(),
                TextColumn::make('importe')->label('Importe')->numeric(2)->alignEnd(),
                TextColumn::make('razon')->label('Razón')->limit(50)->tooltip(fn (SalidaStock $record): ?string => filled($record->razon) ? $record->razon : null)->toggleable(),
                TextColumn::make('estado')->label('Estado')->formatStateUsing(fn (?string $state): string => (string) $state === '1' ? 'Activo' : 'Inactivo')->color(fn (?string $state): string => (string) $state === '1' ? 'success' : 'gray')->badge(),
            ])
            ->recordActions([
                Action::make('detalle')->label('Ver')->icon('heroicon-o-eye')
                    ->visible(fn (): bool => (bool) auth()->user()?->hasPermission('salidas-stock.ver-detalle'))
                    ->modalHeading(fn (SalidaStock $record): string => 'Salida #'.$record->restaurant_id)
                    ->modalWidth('7xl')->modalAlignment(Alignment::Start)->modalSubmitAction(false)->modalCancelActionLabel('Cerrar')->stickyModalHeader()->stickyModalFooter()
                    ->schema([
                        Section::make()->schema([
                            TextEntry::make('fecha')->label('Fecha')->date('d/m/Y'), TextEntry::make('hora')->label('Hora')->placeholder('—'),
                            TextEntry::make('local_nombre')->label('Local')->placeholder('—'), TextEntry::make('categoria')->label('Categoría')->badge()->placeholder('—'),
                            TextEntry::make('responsable')->label('Responsable')->placeholder('—'), TextEntry::make('razon')->label('Razón')->columnSpanFull()->placeholder('—')->wrap(),
                        ])->columns(3),
                        Section::make('Ítems')->schema([
                            RepeatableEntry::make('detalles')->label('')->table([
                                TableColumn::make('Ítem'), TableColumn::make('Almacén'), TableColumn::make('Cantidad')->alignEnd(), TableColumn::make('Unidad'), TableColumn::make('Costo')->alignEnd(), TableColumn::make('Total')->alignEnd(),
                            ])->schema([
                                TextEntry::make('item')->placeholder('—'), TextEntry::make('almacen')->placeholder('—'), TextEntry::make('cantidad')->numeric(decimalPlaces: 3)->alignEnd(), TextEntry::make('unidad')->placeholder('—'), TextEntry::make('costo')->numeric(decimalPlaces: 2)->alignEnd(), TextEntry::make('total')->numeric(decimalPlaces: 2)->alignEnd(),
                            ])->contained(false),
                        ]),
                    ]),
            ])
            ->paginated([10, 25, 50, 100])->defaultPaginationPageOption(10)->emptyStateHeading('No hay salidas en la copia local.');
    }

    public function locales(): array
    {
        return $this->scopeKeyedLocalsToUser(
            SalidaStock::query()->whereNotNull('local_id')->orderBy('local_nombre')->pluck('local_nombre', 'local_id')->all(),
        );
    }

    public function categorias(): array
    {
        return SalidaStock::query()->whereNotNull('categoria')->distinct()->orderBy('categoria')->pluck('categoria', 'categoria')->all();
    }

    private function query(): Builder
    {
        $query = SalidaStock::query()
            ->when($this->desde, fn (Builder $query) => $query->whereDate('fecha', '>=', $this->desde))
            ->when($this->hasta, fn (Builder $query) => $query->whereDate('fecha', '<=', $this->hasta))
            ->when($this->local, fn (Builder $query) => $query->where('local_id', $this->local))
            ->when($this->categoria, fn (Builder $query) => $query->where('categoria', $this->categoria));

        if (auth()->user()?->isRestrictedToLocals()) {
            $query->whereIn('local_id', auth()->user()->assignedLocalIds());
        }

        return $query->orderByDesc('fecha')->orderByDesc('id');
    }
}
