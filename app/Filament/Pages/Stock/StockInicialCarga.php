<?php

namespace App\Filament\Pages\Stock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Jobs\RecalcularSaldoStockJob;
use App\Models\StockInicialDetalle;
use App\Models\StockInicialLocal;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Carga única del stock inicial por local -- el punto de partida (t=0) del
 * saldo en tiempo real. Independiente por completo de "Stock Actual"
 * (Cuadre de stock, que es un espejo de Restaurant): esta tabla es nativa
 * del CRM y nunca sincroniza con el ERP.
 *
 * El universo de ítems para cargar sale del propio histórico de Kardex de
 * ese local (Almacen Principal) -- no hace falta consultar el catálogo de
 * Restaurant en vivo. Clave de ítem SIEMPRE item_id + item_tipo.
 */
class StockInicialCarga extends Page implements HasTable
{
    use InteractsWithTable;
    use ScopesLocalsToUser;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static ?string $navigationLabel = 'Cargar stock inicial';
    protected static ?string $title = 'Cargar stock inicial';
    protected static string|\UnitEnum|null $navigationGroup = 'Stock Inicial';
    protected static ?int $navigationSort = 1;
    protected static ?string $slug = 'stock-inicial/cargar';
    protected string $view = 'filament.pages.stock.stock-inicial-carga';

    public ?string $localId = null;
    public ?StockInicialLocal $cabecera = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('stock-inicial.crear');
    }

    public function mount(): void
    {
        $user = auth()->user();
        $asignados = $user?->isRestrictedToLocals() ? $user->assignedLocalIds() : [];
        $this->localId = $asignados[0] ?? null;
        $this->cargarCabecera();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('localId')
                ->label('Local')
                ->options(fn (): array => $this->localOptions())
                ->native(false)
                ->searchable()
                ->live()
                ->afterStateUpdated(fn () => $this->cargarCabecera())
                ->visible(fn (): bool => ! auth()->user()?->isRestrictedToLocals() || count(auth()->user()?->assignedLocalIds() ?? []) > 1),
        ]);
    }

    /** @return array<string, string> */
    private function localOptions(): array
    {
        return $this->scopeKeyedLocalsToUser(
            DB::table('kardex_movimientos')->select('local_id', 'local_nombre')->distinct()
                ->orderBy('local_nombre')->pluck('local_nombre', 'local_id')->all(),
        );
    }

    private function cargarCabecera(): void
    {
        if (! $this->localId) {
            $this->cabecera = null;

            return;
        }

        $this->cabecera = StockInicialLocal::query()->firstOrCreate(
            ['local_id' => $this->localId],
            ['local_nombre' => $this->localOptions()[$this->localId] ?? $this->localId, 'fecha_carga' => now()->toDateString(), 'estado' => 'borrador'],
        );

        if ($this->cabecera->estado === 'borrador') {
            $this->sincronizarCatalogo();
        }

        $this->resetTable();
    }

    /** Agrega a la carga cualquier ítem del histórico de Kardex de este local que todavía no esté en el borrador. */
    private function sincronizarCatalogo(): void
    {
        $existentes = $this->cabecera->detalles()->get(['item_id', 'item_tipo'])
            ->map(fn ($d) => $d->item_id.'|'.$d->item_tipo)->all();

        $items = DB::table('kardex_movimientos')
            ->where('local_id', $this->localId)
            ->where('almacen', 'Almacen Principal')
            ->selectRaw('item_id, item_tipo, MAX(item_nombre) AS item_nombre, MAX(unidad_medida) AS unidad, MAX(cod_interno) AS item_codigo')
            ->groupBy('item_id', 'item_tipo')
            ->get()
            ->filter(fn ($row) => ! in_array($row->item_id.'|'.$row->item_tipo, $existentes, true));

        foreach ($items as $item) {
            StockInicialDetalle::create([
                'stock_inicial_local_id' => $this->cabecera->id,
                'item_id' => $item->item_id,
                'item_tipo' => $item->item_tipo,
                'item_codigo' => $item->item_codigo,
                'item_nombre' => $item->item_nombre,
                'unidad' => $item->unidad,
                'cantidad_inicial' => 0,
            ]);
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->cabecera
                ? StockInicialDetalle::query()->where('stock_inicial_local_id', $this->cabecera->id)
                : StockInicialDetalle::query()->whereRaw('1 = 0'))
            ->headerActions([
                Action::make('actualizarCatalogo')
                    ->label('Actualizar catálogo')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->visible(fn (): bool => (bool) $this->cabecera?->estado === 'borrador')
                    ->action(function (): void {
                        $this->sincronizarCatalogo();
                        $this->resetTable();
                    }),
                Action::make('confirmarCarga')
                    ->label('Confirmar carga')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (): bool => (bool) $this->cabecera && $this->cabecera->estado === 'borrador')
                    ->requiresConfirmation()
                    ->modalHeading('¿Confirmar el stock inicial de este local?')
                    ->modalDescription('Después de confirmar, esta carga queda bloqueada. Cualquier corrección posterior debe hacerse desde "Ajustar Stock", con motivo, para no perder trazabilidad.')
                    ->modalSubmitActionLabel('Confirmar')
                    ->action(fn () => $this->confirmarCarga()),
            ])
            ->columns([
                TextColumn::make('item_codigo')->label('Cód.'),
                TextColumn::make('item_nombre')->label('Ítem')->searchable()->wrap(),
                TextColumn::make('item_tipo')->label('Tipo')->badge()->color('gray'),
                TextColumn::make('unidad')->label('Unidad'),
                TextInputColumn::make('cantidad_inicial')
                    ->label('Cantidad inicial')
                    ->type('number')
                    ->step('0.0001')
                    ->rules(['numeric', 'min:0'])
                    ->disabled(fn (): bool => (bool) $this->cabecera && $this->cabecera->estado !== 'borrador'),
            ])
            ->defaultSort('item_nombre')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('Selecciona un local para cargar su stock inicial.');
    }

    private function confirmarCarga(): void
    {
        if (! $this->cabecera || $this->cabecera->estado !== 'borrador') {
            return;
        }

        $this->cabecera->update([
            'estado' => 'confirmado',
            'confirmado_en' => now(),
            'cargado_por' => auth()->id(),
        ]);

        RecalcularSaldoStockJob::dispatch($this->localId);

        Notification::make()
            ->success()
            ->title('Stock inicial confirmado')
            ->body('El saldo en tiempo real de este local ya se está calculando.')
            ->send();

        $this->resetTable();
    }
}
