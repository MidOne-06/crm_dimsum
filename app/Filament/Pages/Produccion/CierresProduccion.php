<?php

namespace App\Filament\Pages\Produccion;

use App\Models\ProduccionDiariaCierre;
use App\Services\ProduccionCierreService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class CierresProduccion extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationLabel = 'Cierres de producción';
    protected static ?string $title = 'Cierres de producción';
    protected static string|\UnitEnum|null $navigationGroup = 'Producción';
    protected static ?int $navigationSort = 4;
    protected static ?string $slug = 'produccion/cierres';
    protected string $view = 'filament.pages.produccion.cierres-produccion';
    private string $intentoGuardar = 'borrador';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->hasPermission('produccion-diaria.view') || $user?->hasPermission('produccion-diaria.registrar')
            || $user?->hasPermission('produccion-diaria.registrar-tanda') || $user?->hasPermission('produccion-diaria.aprobar'));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => ProduccionDiariaCierre::query()
                ->with('creador')
                ->withCount([
                    'tandas',
                    'salidas',
                    'detalles',
                    'detalles as detalles_con_fisico_count' => fn (Builder $query): Builder => $query->whereNotNull('stock_final'),
                ]))
            ->columns([
                Tables\Columns\TextColumn::make('fecha')->label('Fecha')->date('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('estado')->label('Estado')->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'borrador' => 'Borrador', 'enviado' => 'Enviado', 'aprobado' => 'Aprobado', default => 'Nuevo',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'borrador' => 'gray', 'enviado' => 'warning', 'aprobado' => 'success', default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('tandas_count')->label('Bachs')->alignEnd(),
                Tables\Columns\TextColumn::make('salidas_count')->label('Salidas')->alignEnd(),
                Tables\Columns\TextColumn::make('fisico')->label('Físico')->alignEnd()
                    ->state(fn (ProduccionDiariaCierre $record): string => "{$record->detalles_con_fisico_count}/{$record->detalles_count}"),
                Tables\Columns\TextColumn::make('creador.name')->label('Registrado por')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('updated_at')->label('Actualizado')->dateTime('d/m H:i')->sortable()->toggleable(),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('detalle')
                        ->label('Ver detalle')
                        ->icon('heroicon-o-eye')
                        ->modalHeading(fn (ProduccionDiariaCierre $record): string => 'Cierre · '.$record->fecha->format('d/m/Y'))
                        ->modalWidth('5xl')
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Cerrar')
                        ->stickyModalHeader()
                        ->stickyModalFooter()
                        ->modalContent(fn (ProduccionDiariaCierre $record) => view('filament.pages.produccion.partials.cierre-produccion-detalle', [
                            'cierre' => $record->load([
                                'creador',
                                'aprobador',
                                'tandas.registrador',
                                'salidas.registrador',
                                'detalles',
                            ]),
                        ])),
                    Action::make('registrar_cierre_fisico')
                        ->label('Registrar cierre físico')
                        ->icon('heroicon-o-clipboard-document-check')
                        ->color('primary')
                        ->visible(fn (ProduccionDiariaCierre $record): bool => $this->puedeRegistrarCierre() && $record->estado === 'borrador')
                        ->modalHeading(fn (ProduccionDiariaCierre $record): string => 'Cierre físico · '.$record->fecha->format('d/m/Y'))
                        ->modalWidth('7xl')
                        ->stickyModalHeader()
                        ->stickyModalFooter()
                        ->modalSubmitActionLabel('Enviar cierre')
                        ->modalCancelActionLabel('Cancelar')
                        ->extraModalFooterActions(fn (Action $action): array => [
                            $action->makeModalSubmitAction('guardar_borrador', ['destino' => 'borrador'])
                                ->label('Guardar borrador')
                                ->color('gray'),
                        ])
                        ->fillForm(fn (ProduccionDiariaCierre $record): array => app(ProduccionCierreService::class)->formData($record->fecha->toDateString(), $record))
                        ->schema($this->cierreFisicoSchema())
                        ->beforeFormValidated(function (Action $action): void {
                            $this->intentoGuardar = ($action->getArguments()['destino'] ?? 'enviado') === 'borrador' ? 'borrador' : 'enviado';
                        })
                        ->action(function (ProduccionDiariaCierre $record, array $data, Action $action): void {
                            abort_unless($this->puedeRegistrarCierre(), 403);
                            $destino = (string) ($action->getArguments()['destino'] ?? 'enviado');

                            try {
                                app(ProduccionCierreService::class)->guardar($record->fecha->toDateString(), $data, $destino, (int) auth()->id());
                            } finally {
                                $this->intentoGuardar = 'borrador';
                            }

                            $this->resetTable();
                            Notification::make()->success()->title($destino === 'enviado' ? 'Cierre enviado para aprobación' : 'Borrador guardado')->send();
                        }),
                ])
                    ->button()
                    ->label('Opciones')
                    ->icon('heroicon-o-ellipsis-horizontal')
                    ->color('gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('estado')->label('Estado')->options([
                    'borrador' => 'Borrador', 'enviado' => 'Enviado', 'aprobado' => 'Aprobado',
                ]),
                Tables\Filters\Filter::make('fecha')->label('Fecha')
                    ->schema([
                        Grid::make(['default' => 1, 'sm' => 2])->schema([
                            DatePicker::make('desde')->label('Desde')->native(false),
                            DatePicker::make('hasta')->label('Hasta')->native(false),
                        ]),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['desde'] ?? null, fn (Builder $q, string $fecha): Builder => $q->whereDate('fecha', '>=', $fecha))
                        ->when($data['hasta'] ?? null, fn (Builder $q, string $fecha): Builder => $q->whereDate('fecha', '<=', $fecha)))
                    ->indicateUsing(function (array $data): array {
                        return collect(['desde' => 'Desde', 'hasta' => 'Hasta'])
                            ->filter(fn (string $label, string $key): bool => filled($data[$key] ?? null))
                            ->map(fn (string $label, string $key): string => $label.' '.Carbon::parse($data[$key])->format('d/m/Y'))
                            ->values()
                            ->all();
                    }),
            ])
            ->defaultSort('fecha', 'desc')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('Sin cierres.');
    }

    /** @return array<int, mixed> */
    private function cierreFisicoSchema(): array
    {
        return [
            Grid::make(1)->columnSpanFull()->schema([
                Textarea::make('observacion')->label('Observación general')->rows(2)->maxLength(1000)->columnSpanFull(),
                Repeater::make('items')->label('')->hiddenLabel()->addable(false)->deletable(false)->reorderable(false)->itemNumbers(false)->compact()->columnSpanFull()
                    ->table([
                        TableColumn::make('Producto')->width('18rem'),
                        TableColumn::make('Unidad')->width('6.5rem'),
                        TableColumn::make('Inicial')->width('7rem'),
                        TableColumn::make('Producido')->width('7rem'),
                        TableColumn::make('Salidas')->width('7rem'),
                        TableColumn::make('Esperado')->width('7rem'),
                        TableColumn::make('Final físico')->width('8rem'),
                        TableColumn::make('Diferencia')->width('7rem'),
                        TableColumn::make('Motivo')->width('15rem'),
                    ])
                    ->schema([
                        Hidden::make('producto_id')->dehydrated(),
                        Hidden::make('origen_inicial')->dehydrated(),
                        Hidden::make('item_codigo')->dehydrated(),
                        TextInput::make('item_nombre')->hiddenLabel()->readOnly()->dehydrated(),
                        TextInput::make('unidad')->hiddenLabel()->readOnly()->dehydrated(),
                        TextInput::make('stock_inicial')->hiddenLabel()->numeric()->minValue(0)->required()->live()
                            ->readOnly(fn (Get $get): bool => $get('origen_inicial') === 'cierre_anterior')
                            ->afterStateUpdated(fn (Get $get, Set $set) => $this->actualizarCalculos($get, $set)),
                        TextInput::make('producido_hoy')->hiddenLabel()->numeric()->readOnly()->dehydrated(),
                        TextInput::make('salidas_hoy')->hiddenLabel()->numeric()->readOnly()->dehydrated(),
                        TextInput::make('stock_esperado')->hiddenLabel()->numeric()->readOnly()->dehydrated(),
                        TextInput::make('stock_final')->hiddenLabel()->numeric()->minValue(0)->inputMode('decimal')->live()
                            ->required(fn (): bool => $this->intentoGuardar === 'enviado')
                            ->afterStateUpdated(fn (Get $get, Set $set) => $this->actualizarCalculos($get, $set)),
                        TextInput::make('diferencia')->hiddenLabel()->numeric()->readOnly()->dehydrated(),
                        TextInput::make('observacion')->hiddenLabel()->maxLength(500)
                            ->required(fn (Get $get): bool => $this->intentoGuardar === 'enviado' && abs((float) ($get('diferencia') ?? 0)) > 0.0001),
                    ]),
            ]),
        ];
    }

    private function actualizarCalculos(Get $get, Set $set): void
    {
        $esperado = round((float) ($get('stock_inicial') ?? 0) + (float) ($get('producido_hoy') ?? 0) - (float) ($get('salidas_hoy') ?? 0), 4);
        $final = $get('stock_final');
        $set('stock_esperado', $esperado);
        $set('diferencia', filled($final) ? round((float) $final - $esperado, 4) : null);
    }

    private function puedeRegistrarCierre(): bool
    {
        return (bool) auth()->user()?->hasPermission('produccion-diaria.registrar');
    }
}
