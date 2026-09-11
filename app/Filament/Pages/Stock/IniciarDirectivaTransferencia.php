<?php

namespace App\Filament\Pages\Stock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Jobs\ExtraerKardexJob;
use App\Models\DirectivaTransferenciaSugerencia;
use App\Models\GuiaInternaSincronizacion;
use App\Models\KardexExtraccion as KardexExtraccionModel;
use App\Models\LocalDiaSinDt;
use App\Models\StockInicialLocal;
use App\Services\DirectivaTransferenciaService;
use App\Services\GuiasInternasHistoricoService;
use App\Services\KardexGatewayClient;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Módulo propio (no un modal encima de otra pantalla) para poner en marcha
 * la Directiva de Transferencia -- pedido explícito del usuario: "debe ser
 * un módulo nuevo no un botón en la vista de directiva-transferencia",
 * pensado para que cualquier persona pueda seguir el flujo sin conocer el
 * sistema por dentro. Reemplaza 2 acciones que antes vivían separadas y se
 * pisaban en su alcance en `DirectivaTransferenciaConsolidado`:
 *
 * - "Iniciar Directiva de Transferencia" (modal con alcance + ajustes, pero
 *   calculaba YA MISMO con los datos que hubiera, sin sincronizar nada).
 * - "Sincronizar y calcular para mañana" (sincronizaba Kardex y Guías
 *   internas primero, pero SIEMPRE con el alcance por defecto -- ignoraba
 *   cualquier exclusión o ajuste, no tenían forma de combinarse).
 *
 * Acá es UN solo flujo: elegís el alcance y los ajustes una vez, y el mismo
 * botón sincroniza y calcula usando esa elección. Si ya sincronizaste hace
 * poco, "Calcular sin sincronizar" usa el mismo formulario sin esperar.
 *
 * El estado (kardexExtraccionId, guiasSincronizacionId, sincronizando) es el
 * mismo mecanismo de poll ya probado en producción -- ver docblock viejo en
 * el historial de git de DirectivaTransferenciaConsolidado.
 */
class IniciarDirectivaTransferencia extends Page
{
    use ScopesLocalsToUser;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-play';
    protected static ?string $navigationLabel = 'Iniciar Directiva de Transferencia';
    protected static ?string $title = 'Iniciar Directiva de Transferencia';
    protected static string|\UnitEnum|null $navigationGroup = 'Stock Inicial';
    protected static ?int $navigationSort = 4;
    protected static ?string $slug = 'stock-inicial/iniciar-directiva';
    protected string $view = 'filament.pages.stock.iniciar-directiva-transferencia';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public ?int $kardexExtraccionId = null;

    public ?int $guiasSincronizacionId = null;

    public bool $sincronizando = false;

    /** Guarda qué eligió el usuario para calcular apenas terminen las 2 sincronizaciones -- ver verificarSincronizacion(). */
    public ?array $datosPendientes = null;

    /** @var array{total: int, locales: int, fecha: string}|null */
    public ?array $ultimoResultado = null;

    public ?string $error = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('directiva-transferencia.view');
    }

    public function mount(): void
    {
        $this->form->fill([
            'modo_alcance' => 'venta_activa',
            'locales_excluir' => [],
            'agregar_dia_sin_dt' => false,
            'dia_sin_dt_locales' => [],
            'aplicar_ajuste' => false,
            'porcentaje_ajuste' => 10,
            'ajuste_locales' => [],
        ]);
    }

    /** @return array<string, string> */
    private function localesConfirmadosOptions(): array
    {
        return $this->scopeKeyedLocalsToUser(
            StockInicialLocal::where('estado', 'confirmado')->orderBy('local_nombre')->pluck('local_nombre', 'local_id')->all(),
        );
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->schema([
                Section::make('Locales')
                    ->compact()
                    ->schema([
                        Radio::make('modo_alcance')
                            ->hiddenLabel()
                            ->options([
                                'venta_activa' => 'Venta activa (3 días)',
                                'todos' => 'Todos',
                                'manual' => 'Todos, excepto...',
                            ])
                            ->live()
                            ->required(),
                        Select::make('locales_excluir')
                            ->label('Excluir')
                            ->options(fn (): array => $this->localesConfirmadosOptions())
                            ->multiple()
                            ->searchable()
                            ->live()
                            ->visible(fn (callable $get): bool => $get('modo_alcance') === 'manual')
                            ->required(fn (callable $get): bool => $get('modo_alcance') === 'manual'),
                    ]),

                Section::make('Ajustes')
                    ->compact()
                    ->collapsed(fn (callable $get): bool => ! $get('agregar_dia_sin_dt') && ! $get('aplicar_ajuste'))
                    ->schema([
                        Toggle::make('agregar_dia_sin_dt')->label('Día sin DT')->live(),
                        Select::make('dia_sin_dt_locales')
                            ->label('Local(es)')
                            ->options(fn (): array => $this->localesConfirmadosOptions())
                            ->multiple()->searchable()
                            ->visible(fn (callable $get): bool => (bool) $get('agregar_dia_sin_dt'))
                            ->required(fn (callable $get): bool => (bool) $get('agregar_dia_sin_dt')),
                        Select::make('dia_sin_dt_dia')
                            ->label('Día')
                            ->options(LocalDiaSinDt::DIAS)
                            ->visible(fn (callable $get): bool => (bool) $get('agregar_dia_sin_dt'))
                            ->required(fn (callable $get): bool => (bool) $get('agregar_dia_sin_dt')),

                        Toggle::make('aplicar_ajuste')->label('Ajuste %')->live(),
                        TextInput::make('porcentaje_ajuste')
                            ->label('Porcentaje')->numeric()->suffix('%')->default(10)->minValue(-100)->maxValue(500)
                            ->visible(fn (callable $get): bool => (bool) $get('aplicar_ajuste'))
                            ->required(fn (callable $get): bool => (bool) $get('aplicar_ajuste')),
                        Select::make('ajuste_locales')
                            ->label('Local(es) (vacío = todos)')
                            ->options(fn (): array => $this->localesConfirmadosOptions())
                            ->multiple()->searchable()
                            ->visible(fn (callable $get): bool => (bool) $get('aplicar_ajuste')),
                    ])
                    ->columns(2),
            ]);
    }

    /** Cuántos locales entran HOY MISMO con lo elegido hasta ahora -- consulta en vivo, no un texto fijo. */
    public function conteoAlcanceEnVivo(): string
    {
        $get = fn (string $key) => $this->data[$key] ?? null;
        $confirmados = StockInicialLocal::where('estado', 'confirmado')->pluck('local_id')->all();

        $incluidos = match ($get('modo_alcance')) {
            'todos' => $confirmados,
            'manual' => array_values(array_diff($confirmados, array_map('strval', (array) $get('locales_excluir')))),
            default => app(DirectivaTransferenciaService::class)->localesConVentaActiva($confirmados),
        };

        return count($incluidos).' de '.count($confirmados).' locales entrarán en esta corrida.';
    }

    private function fechaReferencia(): string
    {
        return now()->toDateString();
    }

    /**
     * Botón principal: sincroniza Kardex (ayer + hoy) y Guías internas, y
     * apenas ambas terminan, calcula con el alcance/ajustes elegidos --
     * ver verificarSincronizacion().
     */
    public function sincronizarYCalcular(): void
    {
        abort_unless(auth()->user()?->hasPermission('directiva-transferencia.view'), 403);
        $data = $this->form->getState();
        $this->error = null;
        $this->ultimoResultado = null;
        $this->datosPendientes = $data;

        $hoy = now()->toDateString();
        $ayer = now()->subDay()->toDateString();

        if (KardexExtraccionModel::query()->whereIn('estado', ['pendiente', 'en_progreso'])->exists()) {
            $this->kardexExtraccionId = KardexExtraccionModel::query()->whereIn('estado', ['pendiente', 'en_progreso'])->latest('id')->value('id');
        } else {
            try {
                $locales = app(KardexGatewayClient::class)->locals();
            } catch (Throwable $exception) {
                $this->error = 'No se pudo iniciar la sincronización de Kardex: '.$exception->getMessage();

                return;
            }
            $localesIds = collect($locales)->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();
            $extraccion = KardexExtraccionModel::create([
                'estado' => 'pendiente',
                'filtros' => [
                    'locales' => implode('-', $localesIds),
                    'localesNombres' => collect($locales)->mapWithKeys(fn (array $l): array => [(string) $l['id'] => (string) ($l['name'] ?? '')])->all(),
                    'motivo' => '-1',
                    'fechaInicio' => $ayer,
                    'fechaFin' => $hoy,
                ],
                'iniciado_por' => auth()->id(),
            ]);
            ExtraerKardexJob::dispatch($extraccion->id)->onQueue('kardex');
            $this->kardexExtraccionId = $extraccion->id;
        }

        if (GuiaInternaSincronizacion::query()->whereIn('estado', ['pendiente', 'en_progreso'])->exists()) {
            $this->guiasSincronizacionId = GuiaInternaSincronizacion::query()->whereIn('estado', ['pendiente', 'en_progreso'])->latest('id')->value('id');
        } else {
            $run = app(GuiasInternasHistoricoService::class)->iniciar(now()->subDays(3)->toDateString(), $hoy, [], auth()->id());
            $this->guiasSincronizacionId = $run->id;
        }

        $this->sincronizando = true;
    }

    /** Llamada por wire:poll mientras `sincronizando` es true -- mismo criterio ya probado en producción. */
    public function verificarSincronizacion(): void
    {
        if (! $this->sincronizando) {
            return;
        }

        $kardex = $this->kardexExtraccionId ? KardexExtraccionModel::find($this->kardexExtraccionId) : null;
        $guias = $this->guiasSincronizacionId ? GuiaInternaSincronizacion::find($this->guiasSincronizacionId) : null;

        $kardexListo = ! $kardex || $kardex->estado === 'completado';
        $guiasListo = ! $guias || in_array($guias->estado, ['completado', 'completado_con_errores'], true);
        $kardexFallo = $kardex?->estado === 'fallido';
        $guiasFallo = $guias?->estado === 'fallido';

        if ($kardexFallo || $guiasFallo) {
            $this->sincronizando = false;
            $detalle = $kardexFallo ? 'la extracción de Kardex' : 'la sincronización de Guías internas';
            $this->error = "No se pudo sincronizar: falló {$detalle}. Revisá el Panel de Sincronización -- no se calculó nada para no usar datos a medias.";

            return;
        }

        if ($kardexListo && $guiasListo) {
            $this->sincronizando = false;
            $this->kardexExtraccionId = null;
            $this->guiasSincronizacionId = null;
            $this->calcular($this->datosPendientes ?? []);
        }
    }

    /** Calcula ya mismo, sin sincronizar -- para cuando los datos ya están frescos y no hace falta esperar. */
    public function calcularSinSincronizar(): void
    {
        abort_unless(auth()->user()?->hasPermission('directiva-transferencia.view'), 403);
        $this->error = null;
        $this->calcular($this->form->getState());
    }

    /** @param  array<string, mixed>  $data */
    private function calcular(array $data): void
    {
        if ($data['agregar_dia_sin_dt'] ?? false) {
            $localesDiaSinDt = $this->restrictLocalIdsToUser(array_map('strval', (array) ($data['dia_sin_dt_locales'] ?? [])));
            foreach ($localesDiaSinDt as $localId) {
                LocalDiaSinDt::firstOrCreate(['local_id' => $localId, 'dia_semana' => (int) $data['dia_sin_dt_dia']]);
            }
        }

        $localesExcluidos = [];
        $soloVentaActiva = false;
        match ($data['modo_alcance'] ?? 'venta_activa') {
            'manual' => $localesExcluidos = $this->restrictLocalIdsToUser(array_map('strval', (array) ($data['locales_excluir'] ?? []))),
            'todos' => null,
            default => $soloVentaActiva = true,
        };

        $porcentajeGlobal = 0.0;
        $porcentajePorLocal = [];
        if ($data['aplicar_ajuste'] ?? false) {
            $pct = (float) ($data['porcentaje_ajuste'] ?? 0);
            $localesAjuste = $this->restrictLocalIdsToUser(array_map('strval', (array) ($data['ajuste_locales'] ?? [])));
            if ($localesAjuste !== []) {
                foreach ($localesAjuste as $localId) {
                    $porcentajePorLocal[$localId] = $pct;
                }
            } else {
                $porcentajeGlobal = $pct;
            }
        }

        $total = app(DirectivaTransferenciaService::class)->calcularParaFecha(
            $this->fechaReferencia(),
            $localesExcluidos,
            $soloVentaActiva,
            $porcentajeGlobal,
            $porcentajePorLocal,
        );

        $calculadoEn = DirectivaTransferenciaSugerencia::query()->max('calculado_en');
        $locales = DirectivaTransferenciaSugerencia::query()->where('calculado_en', $calculadoEn)->distinct()->count('local_id');
        $fecha = DirectivaTransferenciaSugerencia::query()->where('calculado_en', $calculadoEn)->min('fecha_despacho');

        $this->ultimoResultado = ['total' => $total, 'locales' => $locales, 'fecha' => (string) $fecha];
        $this->datosPendientes = null;

        Notification::make()->success()->title('Directiva calculada')->body("{$total} sugerencias generadas para {$locales} locales.")->send();
    }

    /**
     * Foto del estado actual para que cualquier persona vea, sin adivinar,
     * si conviene sincronizar de nuevo o si ya está todo fresco.
     *
     * @return array{corrida: array{existe: bool, calculado_en: ?string, hace: ?string, total: int, locales: int, fecha: ?string}, kardex: array{estado: ?string, hace: ?string}, guias: array{estado: ?string, hace: ?string}}
     */
    public function estadoActual(): array
    {
        $calculadoEn = DirectivaTransferenciaSugerencia::query()->max('calculado_en');
        $corrida = [
            'existe' => filled($calculadoEn),
            'calculado_en' => $calculadoEn,
            'hace' => $calculadoEn ? Carbon::parse($calculadoEn)->diffForHumans() : null,
            'total' => $calculadoEn ? DirectivaTransferenciaSugerencia::query()->where('calculado_en', $calculadoEn)->count() : 0,
            'locales' => $calculadoEn ? DirectivaTransferenciaSugerencia::query()->where('calculado_en', $calculadoEn)->distinct()->count('local_id') : 0,
            'fecha' => $calculadoEn ? DirectivaTransferenciaSugerencia::query()->where('calculado_en', $calculadoEn)->min('fecha_despacho') : null,
        ];

        $kardex = KardexExtraccionModel::query()->where('estado', 'completado')->latest('completado_at')->first();
        $guias = GuiaInternaSincronizacion::query()->whereIn('estado', ['completado', 'completado_con_errores'])->latest('completado_en')->first();

        return [
            'corrida' => $corrida,
            'kardex' => [
                'estado' => $kardex ? 'ok' : null,
                'hace' => $kardex?->completado_at ? Carbon::parse($kardex->completado_at)->diffForHumans() : null,
            ],
            'guias' => [
                'estado' => $guias ? 'ok' : null,
                'hace' => $guias?->completado_en ? $guias->completado_en->diffForHumans() : null,
            ],
        ];
    }

    /**
     * Últimas 5 corridas (agrupadas por instante de cálculo) -- para que
     * cualquiera pueda confirmar "sí corrió" sin tener que abrir la tabla
     * completa de la Directiva ni preguntarle a nadie.
     *
     * @return \Illuminate\Support\Collection<int, array{calculado_en: string, hace: string, total: int, locales: int, fecha: string}>
     */
    public function historial()
    {
        return DirectivaTransferenciaSugerencia::query()
            ->selectRaw('calculado_en, count(*) as total, count(distinct local_id) as locales, min(fecha_despacho) as fecha')
            ->groupBy('calculado_en')
            ->orderByDesc('calculado_en')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'calculado_en' => Carbon::parse($r->calculado_en)->format('d/m/Y H:i'),
                'hace' => Carbon::parse($r->calculado_en)->diffForHumans(),
                'total' => (int) $r->total,
                'locales' => (int) $r->locales,
                'fecha' => Carbon::parse($r->fecha)->format('d/m/Y'),
            ]);
    }
}
