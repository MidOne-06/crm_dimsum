<?php

namespace App\Filament\Pages\Stock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Models\GuiaInterna;
use App\Services\GuiasInternasGatewayClient;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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
    public ?string $local = null;
    public ?string $estado = null;

    public static function canAccess(): bool { return (bool) auth()->user()?->hasPermission('guias-internas.view'); }

    public function mount(): void { $this->desde = now()->subDays(30)->toDateString(); $this->hasta = now()->toDateString(); }
    public function setDateRange(string $start, string $end, ?string $preset = 'custom'): void { $this->desde = $start; $this->hasta = $end; $this->activeDatePreset = $preset ?: 'custom'; $this->resetTable(); }
    public function aplicar(): void { $this->resetTable(); }
    public function sincronizar(): void { abort_unless(auth()->user()?->hasPermission('guias-internas.sincronizar'), 403); Artisan::call('guias-internas:sincronizar', ['--desde' => $this->desde, '--hasta' => $this->hasta]); $this->resetTable(); }

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
                ->modalSubmitAction(false)->modalCancelActionLabel('Cerrar')->schema([
                    Section::make('Documento y relaciones')->description('Vinculaciones recuperadas desde Restaurant durante la sincronización.')->schema([
                        TextEntry::make('restaurant_id')->label('Guía interna'), TextEntry::make('estado')->label('Estado')->badge(),
                        TextEntry::make('requerimiento_restaurant_id')->label('Requerimiento vinculado')->placeholder('Sin vínculo'),
                        TextEntry::make('movimiento_restaurant_id')->label('Movimiento interno')->placeholder('Sin vínculo'),
                    ])->columns(2),
                ]),
            Action::make('anular')->label('Anular guía interna')->icon('heroicon-o-no-symbol')->color('danger')
                ->visible(fn (GuiaInterna $record) => $record->estado_codigo === '1' && (bool) auth()->user()?->hasPermission('guias-internas.anular'))
                ->requiresConfirmation()->modalHeading('¿Anular esta guía interna?')->modalDescription('Esta operación se realizará en Restaurant y no se puede deshacer.')
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
        ])->paginated([10, 25, 50, 100])->defaultPaginationPageOption(25)->emptyStateHeading('No hay guías internas en la copia local.');
    }

    public function locales(): array { return $this->scopeKeyedLocalsToUser(GuiaInterna::query()->whereNotNull('local_origen_id')->orderBy('local_origen')->pluck('local_origen', 'local_origen_id')->all()); }

    private function query(): Builder
    {
        $query = GuiaInterna::query()->when($this->desde, fn ($query) => $query->whereDate('fecha_emision', '>=', $this->desde))
            ->when($this->hasta, fn ($query) => $query->whereDate('fecha_emision', '<=', $this->hasta))
            ->when($this->local, fn ($query) => $query->where('local_origen_id', $this->local))
            ->when($this->estado !== null && $this->estado !== '', fn ($query) => $query->where('estado_codigo', $this->estado));
        if (auth()->user()?->isRestrictedToLocals()) $query->whereIn('local_origen_id', auth()->user()->assignedLocalIds());
        return $query->orderByDesc('fecha_emision')->orderByDesc('id');
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

    public function anularGuia(GuiaInterna $record, bool $devolverCantidades): void
    {
        app(GuiasInternasGatewayClient::class)->anular((string) $record->restaurant_id, $devolverCantidades);
        $record->update(['estado_codigo' => '0', 'estado' => 'Anulado', 'sincronizado_en' => now()]);
        Notification::make()->success()->title('Guía interna anulada')->body('Restaurant confirmó la anulación. La copia local se actualizó.')->send();
        $this->resetTable();
    }
}
