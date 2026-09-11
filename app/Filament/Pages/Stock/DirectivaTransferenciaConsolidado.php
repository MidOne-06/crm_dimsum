<?php

namespace App\Filament\Pages\Stock;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Models\DirectivaTransferenciaSugerencia;
use App\Services\DirectivaTransferenciaExportService;
use App\Services\DirectivaTransferenciaService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cantidad sugerida a despachar -- fase inicial de la Directiva de
 * Transferencia. Revisión completa a propósito (no por excepción todavía):
 * recién estamos empezando, hay que confirmar que el modelo acierta antes
 * de pasar a que solo se resalten los casos raros. Ver
 * DirectivaTransferenciaService para el criterio de cálculo.
 *
 * La pantalla trabaja SIEMPRE sobre despachos futuros (nunca hoy) -- pedido
 * explícito del usuario: la reposición real se decide con anticipación,
 * para poder enviarla a producción antes de que termine hoy, no a último
 * momento cuando ya casi no queda margen. El cálculo automático de las
 * 03:00am (ver routes/console.php) sigue corriendo aparte para "hoy" como
 * red de seguridad -- esta pantalla es la vía manual, disparable en
 * cualquier momento del día.
 *
 * OJO, cambio real del 2026-09-09: la fecha de despacho YA NO es una sola
 * fecha global ("mañana" para todos) -- cada local calcula la suya propia
 * según sus propios días sin DT configurados (`LocalDiaSinDt`, ver
 * `DirectivaTransferenciaService::proximaLlegada()` -- reemplazó a
 * `LocalLogisticaConfig.frecuencia_dias`, que ya no se usa para esto). Un
 * local sin ninguna excepción cae en mañana, igual que siempre; uno con
 * algún día sin DT puede caer más adelante, saltando los días sin llegada.
 * Por eso la tabla filtra "fecha_despacho >= mañana" (trae todas las
 * fechas futuras que existan), no una fecha exacta, y tiene su propia
 * columna "Fecha despacho" para que se distinga cuál es cuál.
 */
class DirectivaTransferenciaConsolidado extends Page implements HasTable
{
    use InteractsWithTable;
    use ScopesLocalsToUser;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';
    protected static ?string $navigationLabel = 'Directiva de transferencia';
    protected static ?string $title = 'Directiva de Transferencia -- Cantidad sugerida';
    protected static string|\UnitEnum|null $navigationGroup = 'Stock Inicial';
    protected static ?int $navigationSort = 5;
    protected static ?string $slug = 'stock-inicial/directiva-transferencia';
    protected string $view = 'filament.pages.stock.directiva-transferencia-consolidado';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('directiva-transferencia.view');
    }

    /**
     * "Hoy" -- la fecha de referencia que recibe
     * DirectivaTransferenciaService::calcularParaFecha(). Cada local calcula
     * su PROPIA fecha de despacho a partir de acá, saltando sus días sin DT
     * -- ya no hay una única "fecha de despacho" global, ver docblock de la
     * clase.
     */
    private function fechaReferencia(): string
    {
        return now()->toDateString();
    }

    /** La fecha más próxima que puede llegar a mostrar la tabla -- todo local, aun uno diario, cae mañana o después, nunca hoy. */
    private function fechaMinimaAMostrar(): string
    {
        return now()->addDay()->toDateString();
    }

    private function tableHeaderActions(): array
    {
        return [
            Action::make('iniciarDirectiva')
                ->label('Iniciar Directiva de Transferencia')
                ->icon('heroicon-o-play')
                ->color('success')
                ->url(fn (): string => \App\Filament\Pages\Stock\IniciarDirectivaTransferencia::getUrl()),
            Action::make('exportarExcel')
                ->label('Exportar Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => $this->exportarExcel()),
            Action::make('exportarPdf')
                ->label('Exportar PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(fn () => $this->exportarPdf()),
            Action::make('recalcular')
                ->label('Recalcular para mañana')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('¿Recalcular la Directiva de mañana?')
                ->modalDescription('Vuelve a calcular la cantidad sugerida para los locales activos (ver "Locales activos") con la fecha de mañana, usando el saldo y el histórico de ventas TAL CUAL están guardados ahora mismo -- no sincroniza nada nuevo. Usa el módulo "Iniciar Directiva de Transferencia" si además querés refrescar Kardex y Guías internas antes.')
                ->action(function (): void {
                    $total = app(DirectivaTransferenciaService::class)->calcularParaFecha($this->fechaReferencia(), soloVentaActiva: true);
                    Notification::make()->success()->title('Directiva recalculada')->body("{$total} sugerencias generadas para mañana.")->send();
                    $this->resetTable();
                }),
        ];
    }

    /**
     * Excel/PDF del pivote (producto x local, fila y columna TOTAL) --
     * armado real en `DirectivaTransferenciaExportService` (compartido con
     * "Iniciar Directiva de Transferencia", que ofrece el mismo export
     * apenas termina de calcular).
     */
    private function exportarExcel(): StreamedResponse
    {
        return app(DirectivaTransferenciaExportService::class)->generarExcel($this->fechaMinimaAMostrar());
    }

    private function exportarPdf(): StreamedResponse
    {
        return app(DirectivaTransferenciaExportService::class)->generarPdf($this->fechaMinimaAMostrar());
    }

    public function table(Table $table): Table
    {
        return $table
            ->headerActions($this->tableHeaderActions())
            ->query(function (): Builder {
                $query = DirectivaTransferenciaSugerencia::query()
                    ->where('fecha_despacho', '>=', $this->fechaMinimaAMostrar());

                if (auth()->user()?->isRestrictedToLocals()) {
                    $query->whereIn('local_id', auth()->user()->assignedLocalIds());
                }

                return $query;
            })
            ->columns([
                TextColumn::make('local_nombre')->label('Local')->searchable()->sortable(),
                TextColumn::make('fecha_despacho')->label('Fecha despacho')->date('d/m/Y')->sortable()
                    ->tooltip('Puede variar por local según sus días sin DT configurados (Configuración DT > Días sin DT).'),
                TextColumn::make('item_codigo')->label('SKU')->searchable(),
                TextColumn::make('item_nombre')->label('Producto')->searchable()->wrap(),
                // Pedido explícito del usuario (2026-09-09): esta columna debe
                // mostrar la demanda de lo que él realmente va a despachar --
                // el tramo 2 (mañana, DESPUÉS de que llegue el transporte,
                // hasta pasado mañana, cuando llega el pedido de hoy) -- no el
                // total combinado de los 2 tramos que se usa puertas adentro
                // para el cálculo. La fórmula de `cantidad_sugerida` NO
                // cambia: sigue restando el stock proyectado del total
                // combinado (`demanda_promedio`, columna de BD), que ya
                // arrastra correctamente cualquier sobrante/faltante del
                // tramo 1 hacia el tramo 2 -- ver DirectivaTransferenciaService.
                // Este es un cambio de qué NÚMERO SE MUESTRA, no de la
                // fórmula: tramo2 = total combinado - tramo 1, calculado al
                // vuelo desde los 2 campos que ya se guardan.
                TextColumn::make('demanda_tramo2')->label('Demanda prom.')->numeric(2)->alignEnd()
                    ->getStateUsing(fn (DirectivaTransferenciaSugerencia $r): float => $r->demanda_promedio - $r->demanda_ventana1)
                    ->tooltip(fn (DirectivaTransferenciaSugerencia $r): string => 'Promedio de las últimas '.$r->semanas_consideradas.' semanas -- SOLO el tramo que este despacho debe cubrir: desde que llega el transporte de mañana hasta que llega el de pasado mañana (el que se genera hoy). No incluye la demanda de ahora-a-mañana (ver "Demanda tramo 1"), que el stock proyectado actual debe aguantar solo.')
                    ->color(fn (DirectivaTransferenciaSugerencia $r): string => $r->esConfianzaBaja() ? 'gray' : 'success'),
                TextColumn::make('demanda_ventana1')->label('Demanda tramo 1')->numeric(2)->alignEnd()->toggleable(isToggledHiddenByDefault: true)
                    ->tooltip('Solo el primer tramo (ahora -> mañana) -- lo que el STOCK PROYECTADO ACTUAL tiene que aguantar por sí solo, sin ayuda del despacho de hoy (llega tarde para este tramo).'),
                TextColumn::make('demanda_promedio')->label('Demanda total (2 tramos)')->numeric(2)->alignEnd()->toggleable(isToggledHiddenByDefault: true)
                    ->tooltip('Ventana completa AHORA hasta PASADO MAÑANA (tramo 1 + tramo 2) -- el número que de verdad usa la fórmula de Cantidad sugerida por dentro (resta el stock proyectado UNA sola vez, arrastrando correctamente el sobrante/faltante del tramo 1 hacia el tramo 2). Se deja visible aparte, oculta por defecto, solo para quien quiera auditar el cálculo completo.'),
                // Visibles por defecto desde el 2026-09-11 (barrida de
                // huecos funcionales, pedido explícito del usuario) --
                // antes empezaban ocultas (isToggledHiddenByDefault: true)
                // y nadie las veía sin saber que existían de antemano.
                TextColumn::make('desviacion_estandar')->label('Desv. estándar')->numeric(2)->alignEnd()->toggleable()
                    ->tooltip('Variabilidad real de venta entre las semanas consideradas (mientras más alta, más volátil el producto en este local) -- es la base del stock de seguridad.')
                    ->color(fn (DirectivaTransferenciaSugerencia $r): string => $r->esConfianzaBaja() ? 'gray' : 'success'),
                TextColumn::make('stock_seguridad')->label('Stock seguridad')->numeric(2)->alignEnd()->toggleable()
                    ->getStateUsing(fn (DirectivaTransferenciaSugerencia $r): float => $r->stockSeguridad())
                    ->tooltip('factor de servicio (configurable en "Stock de seguridad") × desviación estándar, con tope al 100% de la demanda promedio -- colchón real por variabilidad, ya sumado dentro de "Cantidad sugerida" antes del redondeo. Un producto estable da un número chico; uno volátil, uno grande. Gris = calculado con menos de 3 semanas de historia real, poco confiable todavía.')
                    ->color(fn (DirectivaTransferenciaSugerencia $r): string => $r->esConfianzaBaja() ? 'gray' : ($r->stockSeguridad() > 0 ? 'info' : 'gray')),
                TextColumn::make('riesgo_quiebre')->label('¿Riesgo de quiebre?')->badge()
                    ->formatStateUsing(fn ($state): string => $state ? 'Quiebre antes de mañana' : 'Sin riesgo')
                    ->color(fn ($state): string => $state ? 'danger' : 'gray')
                    ->tooltip('Si la demanda del tramo 1 (ahora -> mañana) ya supera el stock proyectado actual, el local se queda sin stock ANTES de que llegue la reposición de mañana -- el despacho de hoy no lo puede evitar, llega después de que ya pasó.'),
                TextColumn::make('semanas_consideradas')->label('Semanas')->alignEnd()->toggleable()
                    ->badge()->color(fn (DirectivaTransferenciaSugerencia $r): string => $r->esConfianzaBaja() ? 'warning' : 'gray'),
                TextColumn::make('saldo_actual')->label('Saldo actual')->numeric(2)->alignEnd()
                    ->color(fn ($state): string => (float) $state < 0 ? 'danger' : 'gray'),
                TextColumn::make('cantidad_en_transito')->label('En tránsito')->numeric(2)->alignEnd()->toggleable()
                    ->tooltip('Guías internas con fecha de traslado entre hoy y mañana (el tramo 1, ambas incluidas), todavía sin confirmar recepción -- ya sumadas al stock proyectado antes de calcular la sugerencia.')
                    ->color(fn ($state): string => (float) $state > 0 ? 'info' : 'gray'),
                TextColumn::make('multiplo_aplicado')->label('Múltiplo')->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('porcentaje_ajuste_aplicado')->label('Ajuste %')->alignEnd()->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn ($state): string => $state != 0 ? number_format((float) $state, 2).'%' : '--')
                    ->tooltip('% aplicado con el wizard "Iniciar Directiva de Transferencia" sobre la cantidad bruta de esta fila -- 0 = sin ajuste, fórmula normal.')
                    ->color(fn ($state): string => (float) $state != 0 ? 'warning' : 'gray'),
                TextColumn::make('cantidad_sugerida')->label('Cantidad sugerida')->numeric()->alignEnd()->sortable()
                    ->weight('bold')->color('primary')->badge(),
            ])
            ->filters([
                SelectFilter::make('local_id')->label('Local')
                    ->options(fn (): array => $this->scopeKeyedLocalsToUser(
                        DirectivaTransferenciaSugerencia::query()->where('fecha_despacho', '>=', $this->fechaMinimaAMostrar())
                            ->distinct()->pluck('local_nombre', 'local_id')->all(),
                    ))->searchable(),
                Filter::make('confianza_baja')->label('Confianza baja (< 3 semanas de histórico)')
                    ->query(fn (Builder $query): Builder => $query->where('semanas_consideradas', '<', 3)),
                Filter::make('riesgo_quiebre')->label('Riesgo de quiebre antes de mañana')
                    ->query(fn (Builder $query): Builder => $query->where('riesgo_quiebre', true)),
            ])
            ->defaultSort('fecha_despacho')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('Todavía no se calculó la Directiva de Transferencia de mañana -- usa "Recalcular para mañana" o "Sincronizar y calcular para mañana".');
    }
}
