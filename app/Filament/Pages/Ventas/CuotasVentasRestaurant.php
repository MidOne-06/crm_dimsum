<?php

namespace App\Filament\Pages\Ventas;

use App\Models\CuotaVentaRestaurant;
use App\Services\CuotasVentasRestaurantService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\View;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class CuotasVentasRestaurant extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Cuotas';

    protected static ?string $title = 'Cuotas mensuales';

    protected static string|\UnitEnum|null $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'ventas/cuotas';

    protected string $view = 'filament.pages.ventas.cuotas-restaurant';

    public string $periodo = '';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('ventas.cuotas.view');
    }

    public function mount(): void
    {
        $this->periodo = now()->startOfMonth()->toDateString();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('periodo')
                ->label(fn (): string => Carbon::parse($this->periodo)->translatedFormat('F Y'))
                ->icon('heroicon-o-calendar-days')
                ->color('gray')
                ->modalHeading('Mes de cuotas')
                ->modalWidth('5xl')
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->modalSubmitActionLabel('Ver cuotas')
                ->modalCancelActionLabel('Cancelar')
                ->fillForm(fn (): array => ['periodo' => $this->periodo])
                ->schema([
                    Grid::make(['default' => 1, 'md' => 4])->schema([
                        DatePicker::make('periodo')->label('Mes')->native(false)->required()->columnSpan(['md' => 2]),
                    ]),
                ])
                ->action(function (array $data): void {
                    $this->periodo = Carbon::parse((string) $data['periodo'])->startOfMonth()->toDateString();
                    $this->resetTable();
                }),
            Action::make('plantilla')
                ->label('Descargar plantilla')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => $this->puedeEditar())
                ->action(function () {
                    abort_unless($this->puedeEditar(), 403);
                    $libro = $this->service()->plantilla($this->periodo);
                    $nombre = 'cuotas-restaurant-'.Carbon::parse($this->periodo)->format('Y-m').'.xlsx';

                    return response()->streamDownload(function () use ($libro): void {
                        try {
                            (new Xlsx($libro))->save('php://output');
                        } finally {
                            $libro->disconnectWorksheets();
                        }
                    }, $nombre, [
                        'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ]);
                }),
            Action::make('copiarMes')
                ->label('Copiar mes')
                ->icon('heroicon-o-document-duplicate')
                ->color('gray')
                ->visible(fn (): bool => $this->puedeEditar())
                ->modalHeading('Copiar cuotas mensuales')
                ->modalWidth('5xl')
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->modalSubmitActionLabel('Copiar cuotas')
                ->modalCancelActionLabel('Cancelar')
                ->fillForm(fn (): array => [
                    'origen' => $this->periodo,
                    'destino' => Carbon::parse($this->periodo)->addMonth()->toDateString(),
                    'sobrescribir' => false,
                ])
                ->schema([
                    Grid::make(['default' => 1, 'md' => 4])->schema([
                        Select::make('origen')->label('Mes origen')->options(fn (): array => $this->opcionesPeriodos())->native(false)->required()->columnSpan(['md' => 2]),
                        DatePicker::make('destino')->label('Mes destino')->native(false)->required()->columnSpan(['md' => 2]),
                        Toggle::make('sobrescribir')->label('Reemplazar cuotas existentes')->default(false)->columnSpanFull(),
                    ]),
                ])
                ->action(function (array $data): void {
                    abort_unless($this->puedeEditar(), 403);
                    $total = $this->service()->copiarMes($data['origen'], $data['destino'], (bool) ($data['sobrescribir'] ?? false), auth()->user());
                    $this->periodo = Carbon::parse((string) $data['destino'])->startOfMonth()->toDateString();
                    $this->resetTable();
                    Notification::make()->success()->title("{$total} cuotas copiadas")->send();
                }),
            Action::make('importar')
                ->label('Importar Excel')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->visible(fn (): bool => $this->puedeEditar())
                ->modalHeading('Importar cuotas mensuales')
                ->modalWidth('5xl')
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->modalSubmitActionLabel('Importar cuotas')
                ->modalCancelActionLabel('Cancelar')
                ->fillForm(fn (): array => ['periodo' => $this->periodo])
                ->schema([
                    Grid::make(['default' => 1, 'md' => 4])->schema([
                        DatePicker::make('periodo')->label('Mes destino')->native(false)->live()->required()->columnSpan(['md' => 2]),
                        FileUpload::make('archivo')->label('Archivo Excel')->disk('local')->directory('imports/cuotas-restaurant')->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->maxSize(5120)->live()->required()->columnSpan(['md' => 2]),
                        Toggle::make('sobrescribir')->label('Reemplazar cuotas existentes')->live()->default(false)->columnSpanFull(),
                        View::make('filament.pages.ventas.partials.cuota-restaurant-resumen-importacion')->columnSpanFull(),
                    ]),
                ])
                ->action(function (array $data): void {
                    abort_unless($this->puedeEditar(), 403);
                    $ruta = (string) $data['archivo'];
                    try {
                        $total = $this->service()->importarExcel(
                            Storage::disk('local')->path($ruta),
                            $data['periodo'],
                            auth()->user(),
                            (bool) ($data['sobrescribir'] ?? false),
                        );
                        Storage::disk('local')->delete($ruta);
                    } catch (\Throwable $exception) {
                        $mensaje = $exception instanceof \InvalidArgumentException
                            ? $exception->getMessage()
                            : 'No se pudo leer el archivo. Verifica que sea una plantilla Excel válida.';
                        throw ValidationException::withMessages(['archivo' => $mensaje]);
                    }
                    $this->periodo = Carbon::parse((string) $data['periodo'])->startOfMonth()->toDateString();
                    $this->resetTable();
                    Notification::make()->success()->title("{$total} cuotas importadas")->send();
                }),
            Action::make('agregar')
                ->label('Agregar local')
                ->icon('heroicon-o-plus')
                ->visible(fn (): bool => $this->puedeEditar())
                ->modalHeading('Agregar cuota mensual')
                ->modalWidth('5xl')
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->modalSubmitActionLabel('Guardar cuota')
                ->modalCancelActionLabel('Cancelar')
                ->schema([
                    Grid::make(['default' => 1, 'md' => 4])->schema([
                        Select::make('codigo')->label('Local Restaurant')->options(fn (): array => $this->opcionesLocales())->native(false)->searchable()->required()->columnSpan(['md' => 2]),
                        TextInput::make('cuota_sin_igv')->label('Cuota sin IGV')->numeric()->prefix('S/')->minValue(0)->required(),
                        TextInput::make('cuota_con_igv')->label('Cuota con IGV')->numeric()->prefix('S/')->minValue(0)->required(),
                    ]),
                ])
                ->action(function (array $data): void {
                    abort_unless($this->puedeEditar(), 403);
                    $catalogo = $this->catalogoPorCodigo()->get((string) $data['codigo']);
                    abort_unless($catalogo !== null, 422, 'El local seleccionado no está disponible.');
                    $this->service()->guardar([
                        'codigo' => $catalogo->codigo,
                        'local_id' => $catalogo->local_id,
                        'local' => $catalogo->local,
                        'cuota_sin_igv' => $data['cuota_sin_igv'],
                        'cuota_con_igv' => $data['cuota_con_igv'],
                    ], $this->periodo, auth()->user());
                    $this->resetTable();
                    Notification::make()->success()->title('Cuota mensual guardada')->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => CuotaVentaRestaurant::query()->whereDate('periodo', $this->periodo)->withCount('auditorias')->orderBy('codigo'))
            ->columns([
                TextColumn::make('codigo')->label('Código')->searchable(),
                TextColumn::make('local')->label('Local')->searchable()->wrap(),
                TextColumn::make('cuota_sin_igv')->label('Sin IGV')->money('PEN')->alignEnd(),
                TextColumn::make('cuota_con_igv')->label('Con IGV')->money('PEN')->alignEnd(),
                TextColumn::make('updated_at')->label('Actualizado')->dateTime('d/m/Y H:i')->toggleable(),
                TextColumn::make('auditorias_count')->label('Historial')->badge()->alignCenter()->color('gray'),
            ])
            ->recordActions([
                Action::make('editar')
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (): bool => $this->puedeEditar())
                    ->modalHeading(fn (CuotaVentaRestaurant $record): string => 'Cuota · '.$record->codigo)
                    ->modalWidth('5xl')
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->modalSubmitActionLabel('Guardar cambios')
                    ->modalCancelActionLabel('Cancelar')
                    ->fillForm(fn (CuotaVentaRestaurant $record): array => ['cuota_sin_igv' => $record->cuota_sin_igv, 'cuota_con_igv' => $record->cuota_con_igv])
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 4])->schema([
                            TextInput::make('cuota_sin_igv')->label('Cuota sin IGV')->numeric()->prefix('S/')->minValue(0)->required()->columnSpan(['md' => 2]),
                            TextInput::make('cuota_con_igv')->label('Cuota con IGV')->numeric()->prefix('S/')->minValue(0)->required()->columnSpan(['md' => 2]),
                        ]),
                    ])
                    ->action(function (CuotaVentaRestaurant $record, array $data): void {
                        abort_unless($this->puedeEditar(), 403);
                        $this->service()->guardar([
                            'codigo' => $record->codigo,
                            'local_id' => $record->local_id,
                            'local' => $record->local,
                            'cuota_sin_igv' => $data['cuota_sin_igv'],
                            'cuota_con_igv' => $data['cuota_con_igv'],
                        ], $record->periodo, auth()->user());
                        $this->resetTable();
                        Notification::make()->success()->title('Cuota mensual actualizada')->send();
                    }),
                Action::make('historial')
                    ->label('Historial')
                    ->icon('heroicon-o-clock')
                    ->modalHeading(fn (CuotaVentaRestaurant $record): string => 'Historial · '.$record->codigo)
                    ->modalWidth('5xl')
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar')
                    ->modalContent(fn (CuotaVentaRestaurant $record) => view('filament.pages.ventas.partials.cuota-restaurant-historial', ['auditorias' => $record->auditorias()->with('usuario')->latest('id')->get()])),
            ])
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('No hay cuotas para el mes seleccionado.');
    }

    /** @return array<string, string> */
    private function opcionesPeriodos(): array
    {
        return CuotaVentaRestaurant::query()->select('periodo')->distinct()->orderByDesc('periodo')->pluck('periodo')
            ->mapWithKeys(fn (mixed $periodo): array => [Carbon::parse($periodo)->startOfMonth()->toDateString() => Carbon::parse($periodo)->translatedFormat('F Y')])->all();
    }

    /** @return array<string, string> */
    private function opcionesLocales(): array
    {
        return $this->catalogoPorCodigo()->mapWithKeys(fn (CuotaVentaRestaurant $cuota): array => [$cuota->codigo => $cuota->codigo.' · '.$cuota->local])->all();
    }

    /** @return \Illuminate\Support\Collection<string, CuotaVentaRestaurant> */
    private function catalogoPorCodigo(): \Illuminate\Support\Collection
    {
        return CuotaVentaRestaurant::query()->orderByDesc('periodo')->orderBy('codigo')->get()->unique('codigo')->keyBy('codigo');
    }

    private function puedeEditar(): bool
    {
        return (bool) auth()->user()?->hasPermission('ventas.cuotas.edit');
    }

    private function service(): CuotasVentasRestaurantService
    {
        return app(CuotasVentasRestaurantService::class);
    }
}
