<?php

namespace App\Filament\Pages\Stock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Jobs\RecalcularSaldoStockJob;
use App\Models\StockInicialAjuste as StockInicialAjusteModel;
use App\Models\StockInicialLocal;
use App\Models\StockSaldoActual;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Corrección auditada sobre un stock inicial ya confirmado. Nunca
 * sobreescribe `stock_iniciales_detalles` -- registra el ajuste con motivo
 * y autor, y el saldo se recalcula desde cero incluyéndolo. Permiso
 * separado (`stock-inicial.ajustar`) del de la carga (`stock-inicial.crear`)
 * a propósito: segregación de funciones real entre quien carga y quien
 * corrige.
 */
class StockInicialAjuste extends Page implements HasTable
{
    use InteractsWithTable;
    use ScopesLocalsToUser;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-vertical';
    protected static ?string $navigationLabel = 'Ajustar stock';
    protected static ?string $title = 'Ajustar stock inicial';
    protected static string|\UnitEnum|null $navigationGroup = 'Stock Inicial';
    protected static ?int $navigationSort = 2;
    protected static ?string $slug = 'stock-inicial/ajustar';
    protected string $view = 'filament.pages.stock.stock-inicial-ajuste';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('stock-inicial.ajustar');
    }

    public function mount(): void
    {
        $this->form->fill(['local_id' => null, 'item_key' => null, 'cantidad_ajuste' => null, 'motivo' => '']);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'md' => 2])->schema([
                Select::make('local_id')->label('Local')
                    ->options(fn (): array => $this->scopeKeyedLocalsToUser(
                        StockInicialLocal::query()->where('estado', 'confirmado')->pluck('local_nombre', 'local_id')->all(),
                    ))
                    ->native(false)->searchable()->required()->live()
                    ->afterStateUpdated(fn ($set) => $set('item_key', null)),
                Select::make('item_key')->label('Ítem')
                    ->options(fn ($get): array => $this->itemOptions((string) $get('local_id')))
                    ->native(false)->searchable()->required()
                    ->disabled(fn ($get): bool => blank($get('local_id'))),
                TextInput::make('cantidad_ajuste')->label('Cantidad de ajuste (+/-)')
                    ->numeric()->required()->helperText('Positivo para sumar, negativo para restar.'),
                Textarea::make('motivo')->label('Motivo del ajuste')->required()->rows(2)->columnSpanFull(),
            ]),
        ])->statePath('data');
    }

    /** @return array<string, string> */
    private function itemOptions(string $localId): array
    {
        if (blank($localId) || ! $this->localAllowedForUser($localId)) {
            return [];
        }

        return StockSaldoActual::query()->where('local_id', $localId)->orderBy('item_nombre')->get()
            ->mapWithKeys(fn (StockSaldoActual $s): array => [
                "{$s->item_id}|{$s->item_tipo}" => trim(($s->item_codigo ? "{$s->item_codigo} · " : '').$s->item_nombre." (saldo actual: {$s->saldo})"),
            ])->all();
    }

    public function guardar(): void
    {
        $data = $this->form->getState();
        $localId = (string) $data['local_id'];

        if (! $this->localAllowedForUser($localId)) {
            Notification::make()->danger()->title('No tienes acceso a ese local')->send();

            return;
        }

        [$itemId, $itemTipo] = array_pad(explode('|', (string) $data['item_key'], 2), 2, null);
        $localNombre = StockInicialLocal::where('local_id', $localId)->value('local_nombre');
        $itemNombre = StockSaldoActual::where('local_id', $localId)->where('item_id', $itemId)->where('item_tipo', $itemTipo)->value('item_nombre');

        StockInicialAjusteModel::create([
            'local_id' => $localId,
            'local_nombre' => $localNombre,
            'item_id' => $itemId,
            'item_tipo' => $itemTipo,
            'item_nombre' => $itemNombre,
            'cantidad_ajuste' => (float) $data['cantidad_ajuste'],
            'motivo' => (string) $data['motivo'],
            'ajustado_por' => auth()->id(),
            'ajustado_en' => now(),
        ]);

        RecalcularSaldoStockJob::dispatch($localId);

        Notification::make()->success()->title('Ajuste registrado')->body('El saldo se recalcula en segundo plano.')->send();

        $this->form->fill(['local_id' => $localId, 'item_key' => null, 'cantidad_ajuste' => null, 'motivo' => '']);
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => StockInicialAjusteModel::query()->orderByDesc('ajustado_en'))
            ->columns([
                TextColumn::make('ajustado_en')->label('Fecha')->dateTime('d/m/Y H:i'),
                TextColumn::make('local_nombre')->label('Local'),
                TextColumn::make('item_nombre')->label('Ítem')->wrap(),
                TextColumn::make('cantidad_ajuste')->label('Ajuste')->numeric(4)
                    ->color(fn ($state): string => (float) $state >= 0 ? 'success' : 'danger'),
                TextColumn::make('motivo')->label('Motivo')->wrap()->limit(80),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('Sin ajustes registrados todavía.');
    }
}
