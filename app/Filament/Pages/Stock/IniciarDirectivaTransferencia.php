<?php

namespace App\Filament\Pages\Stock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Jobs\CalcularDirectivaTrasSincronizacionJob;
use App\Jobs\ExtraerKardexJob;
use App\Models\DirectivaTransferenciaSolicitud;
use App\Models\DirectivaTransferenciaSugerencia;
use App\Models\GuiaInternaSincronizacion;
use App\Models\KardexExtraccion as KardexExtraccionModel;
use App\Models\StockInicialLocal;
use App\Services\DirectivaTransferenciaExportService;
use App\Services\GuiasInternasGatewayClient;
use App\Services\GuiasInternasHistoricoService;
use App\Services\KardexGatewayClient;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
 * Acá es UN solo flujo: elegís los ajustes una vez, y el único botón
 * ("Generar DT" -- pedido explícito del usuario, es el que siempre se usa)
 * sincroniza Kardex y Guías internas y calcula usando esa elección.
 *
 * El estado (kardexExtraccionId, guiasSincronizacionId, sincronizando) es el
 * mismo mecanismo de poll ya probado en producción -- ver docblock viejo en
 * el historial de git de DirectivaTransferenciaConsolidado.
 *
 * 2026-09-11, pedido explícito del usuario ("debe ser lo más práctico y
 * directo"): sin texto explicativo ni indicadores de estado -- se quitó el
 * botón "Calcular sin sincronizar" (nunca es el que se usa en la práctica),
 * el contador en vivo de locales, y la foto de estado (última corrida/
 * Kardex/Guías). Solo el formulario y el botón.
 *
 * 2026-09-11 (segunda vuelta), pedido explícito del usuario: el alcance
 * (Locales activos / Todos / Todos, excepto...) se quitó de este módulo --
 * la corrida siempre calcula sobre `LocalesActivos` (ver esa página), que
 * ahora es la única fuente de verdad para qué locales entran. El toggle
 * "Día sin DT" también se quitó -- esa excepción se carga en su propio
 * módulo dedicado (`LocalDiaSinDtResource`), no acá.
 *
 * 2026-09-12 (barrida de huecos funcionales, pedido explícito del usuario):
 * dos huecos reales cerrados. (1) El cálculo dependía por completo de que
 * esta pestaña siguiera abierta con su `wire:poll` -- cerrarla, perder
 * conexión, o que el celular la mande a segundo plano dejaba las
 * sincronizaciones terminando bien mientras la Directiva (con el % de
 * ajuste elegido) nunca se calculaba, sin ningún aviso. Ahora `generarDt()`
 * persiste la intención de cálculo (`DirectivaTransferenciaSolicitud`) y
 * `CalcularDirectivaTrasSincronizacionJob` la termina server-side; `wire:poll`
 * solo viene a LEER el resultado. (2) No había detección de estancamiento
 * -- si Kardex o Guías internas quedaba trabado (pasó de verdad varias
 * veces este mismo mes, ver bitácora de esos módulos), esta pantalla
 * esperaba para siempre sin avisar ni ofrecer salida. El job ahora marca la
 * solicitud como fallida tras 20 min sin avance real, y la pantalla ofrece
 * "Cancelar espera" en cuanto pasan unos minutos.
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

    /**
     * Id de la solicitud persistida (ver `DirectivaTransferenciaSolicitud`)
     * -- el cálculo real corre en `CalcularDirectivaTrasSincronizacionJob`,
     * server-side, sin depender de que esta pestaña siga abierta (hueco
     * real cerrado 2026-09-12, barrida de huecos funcionales pedida por el
     * usuario: antes, cerrar la pestaña mientras sincronizaba dejaba el
     * cálculo sin hacerse, en silencio). `wire:poll` solo viene a leer el
     * resultado, no a calcular nada él mismo.
     */
    public ?int $solicitudId = null;

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
                Section::make('Ajustes')
                    ->compact()
                    ->collapsed(fn (callable $get): bool => ! $get('aplicar_ajuste'))
                    ->schema([
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
                    ]),
            ]);
    }

    private function fechaReferencia(): string
    {
        return now()->toDateString();
    }

    /**
     * "Generar DT" -- el único botón, siempre se usa: sincroniza Kardex
     * (ayer + hoy) y Guías internas, y deja registrada la intención de
     * cálculo (`DirectivaTransferenciaSolicitud`) para que
     * `CalcularDirectivaTrasSincronizacionJob` termine el trabajo
     * server-side apenas ambas terminen -- ver docblock de esa migración.
     */
    public function generarDt(): void
    {
        abort_unless(auth()->user()?->hasPermission('directiva-transferencia.view'), 403);
        $data = $this->form->getState();
        $this->error = null;
        $this->ultimoResultado = null;

        // Restringido al alcance real del usuario ACÁ, mientras todavía hay
        // sesión autenticada (auth()) -- el job de cola corre sin ella, así
        // que la solicitud ya guarda la lista final, nunca la cruda del
        // formulario (mismo principio que localAllowedForUser(): un
        // wire:model es editable por el cliente, nunca confiar en él solo).
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
            try {
                // OJO, bug real encontrado en producción (2026-09-11): un
                // `locales=[]` acá NO significa "todos" para el gateway --
                // sin lista explícita, Restaurant cae al local de la propia
                // sesión de login (uno solo, no la cadena real), así que la
                // sincronización nunca traía guías nuevas para casi ningún
                // local. Hay que pedir la lista real y pasarla explícita,
                // igual que ya hace "Extracción de guías internas".
                $localesGuias = collect(app(GuiasInternasGatewayClient::class)->locales())
                    ->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();
            } catch (Throwable $exception) {
                $this->error = 'No se pudo iniciar la sincronización de Guías internas: '.$exception->getMessage();

                return;
            }
            $run = app(GuiasInternasHistoricoService::class)->iniciar(now()->subDays(3)->toDateString(), $hoy, $localesGuias, auth()->id());
            $this->guiasSincronizacionId = $run->id;
        }

        $solicitud = DirectivaTransferenciaSolicitud::create([
            'fecha_referencia' => $this->fechaReferencia(),
            'kardex_extraccion_id' => $this->kardexExtraccionId,
            'guia_sincronizacion_id' => $this->guiasSincronizacionId,
            'porcentaje_ajuste_global' => $porcentajeGlobal,
            'porcentaje_ajuste_por_local' => $porcentajePorLocal,
            'estado' => 'pendiente',
            'iniciado_por' => auth()->id(),
        ]);
        CalcularDirectivaTrasSincronizacionJob::dispatch($solicitud->id);

        $this->solicitudId = $solicitud->id;
        $this->sincronizando = true;
    }

    /**
     * Cuántos minutos lleva esperando esta solicitud -- para avisar en
     * pantalla si se está demorando de más, en vez de un spinner mudo
     * indefinido (hueco real cerrado 2026-09-12: el estancamiento de una
     * sincronización ya pasó de verdad varias veces en este proyecto, ver
     * bitácora de Guías internas/Salidas de stock).
     */
    public function minutosEsperando(): int
    {
        if (! $this->solicitudId) {
            return 0;
        }
        $solicitud = DirectivaTransferenciaSolicitud::find($this->solicitudId);

        return $solicitud ? (int) $solicitud->created_at->diffInMinutes(now()) : 0;
    }

    /** Llamada por wire:poll mientras `sincronizando` es true -- ahora solo LEE el resultado, el cálculo ya lo hizo (o lo está haciendo) el job en cola. */
    public function verificarSincronizacion(): void
    {
        if (! $this->sincronizando || ! $this->solicitudId) {
            return;
        }

        $solicitud = DirectivaTransferenciaSolicitud::find($this->solicitudId);
        if (! $solicitud || ! $solicitud->terminada()) {
            return;
        }

        $this->sincronizando = false;
        $this->kardexExtraccionId = null;
        $this->guiasSincronizacionId = null;

        if ($solicitud->estado === 'fallido') {
            $this->error = $solicitud->mensaje_error ?: 'No se pudo calcular la Directiva.';

            return;
        }

        if ($solicitud->estado === 'cancelado') {
            return;
        }

        $this->ultimoResultado = $solicitud->resultado;
        Notification::make()->success()->title('Directiva calculada')
            ->body("{$solicitud->resultado['total']} sugerencias generadas para {$solicitud->resultado['locales']} locales.")->send();

        // Pedido explícito del usuario: apenas termina de calcular, ofrecer
        // el export en un modal automático -- sin que nadie tenga que ir a
        // buscar el botón en el Consolidado.
        $this->dispatch('open-modal', id: 'exportar-directiva');
    }

    /**
     * Deja de esperar esta solicitud en PANTALLA -- no cancela la
     * sincronización real de Kardex/Guías (esa se cancela desde su propia
     * pantalla), solo evita que el job siga escribiendo un resultado que
     * ya nadie espera si el usuario decide no seguir esperando.
     */
    public function cancelarEspera(): void
    {
        if ($this->solicitudId) {
            DirectivaTransferenciaSolicitud::where('id', $this->solicitudId)->where('estado', 'pendiente')->update(['estado' => 'cancelado', 'completado_en' => now()]);
        }
        $this->sincronizando = false;
        $this->solicitudId = null;
        $this->kardexExtraccionId = null;
        $this->guiasSincronizacionId = null;
    }

    public function exportarPdf(): ?StreamedResponse
    {
        if (! $this->ultimoResultado) {
            return null;
        }

        return app(DirectivaTransferenciaExportService::class)->generarPdf($this->ultimoResultado['fecha']);
    }

    public function exportarExcel(): ?StreamedResponse
    {
        if (! $this->ultimoResultado) {
            return null;
        }

        return app(DirectivaTransferenciaExportService::class)->generarExcel($this->ultimoResultado['fecha']);
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
