<?php

namespace App\Filament\Pages;

use App\Models\ConfiguracionSincronizacion;
use App\Models\GuiaInternaSincronizacion;
use App\Models\KardexExtraccion;
use App\Models\RequerimientoStockSincronizacion;
use App\Models\SalidaStockSincronizacion;
use App\Models\StockCuadreSoporte;
use App\Models\VentaExtraccion;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Pedido explícito del usuario: poder prender/apagar cada sincronización
 * automática desde la UI (sin editar código ni hacer un deploy), ver el
 * detalle de la última corrida de cada módulo, y un panel de estados único
 * en vez de tener que entrar a 6 pantallas distintas para saber qué está
 * pasando. El toggle solo controla el ciclo automático (routes/console.php,
 * vía ConfiguracionSincronizacion::activo()) -- nunca bloquea una extracción
 * que un usuario haya encolado a mano desde su propia pantalla de módulo.
 */
class PanelSincronizacion extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-signal';
    protected static ?string $navigationLabel = 'Panel de sincronización';
    protected static ?string $title = 'Panel de sincronización';
    protected static string|\UnitEnum|null $navigationGroup = 'Sincronización';
    protected static ?int $navigationSort = 1;
    protected static ?string $slug = 'sincronizacion';
    protected string $view = 'filament.pages.panel-sincronizacion';

    public ?string $moduloDetalle = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('sincronizacion.manage');
    }

    public function toggle(string $modulo): void
    {
        if (! auth()->user()?->hasPermission('sincronizacion.manage')) {
            Notification::make()->title('No tienes permiso para gestionar la sincronización.')->danger()->send();

            return;
        }

        $config = ConfiguracionSincronizacion::firstOrCreate(['modulo' => $modulo], ['nombre' => $modulo, 'activo' => true]);

        if ($config->activo) {
            $config->desactivar(auth()->user()?->name);
            Notification::make()->title("Sincronización de {$config->nombre} desactivada")->body('El ciclo automático no volverá a correr hasta que la reactives. Las extracciones manuales siguen funcionando igual.')->warning()->send();
        } else {
            $config->activar();
            Notification::make()->title("Sincronización de {$config->nombre} reactivada")->success()->send();
        }
    }

    public function verHistorial(string $modulo): void
    {
        $this->moduloDetalle = $modulo;
        $this->dispatch('open-modal', id: 'historial-sincronizacion');
    }

    public function cerrarHistorial(): void
    {
        $this->moduloDetalle = null;
        $this->dispatch('close-modal', id: 'historial-sincronizacion');
    }

    /** @return array<int, array<string, mixed>> */
    public function resumen(): array
    {
        $configs = ConfiguracionSincronizacion::query()->get()->keyBy('modulo');

        return collect([
            ['modulo' => 'stock-actual', 'nombre' => 'Stock Actual', 'cadencia' => 'Cada 30 min'],
            ['modulo' => 'salidas-stock', 'nombre' => 'Salidas de stock', 'cadencia' => 'Cada hora'],
            ['modulo' => 'guias-internas', 'nombre' => 'Guías internas', 'cadencia' => 'Cada 30 min'],
            ['modulo' => 'requerimientos-stock', 'nombre' => 'Requerimientos de stock', 'cadencia' => 'Cada 30 min'],
            ['modulo' => 'kardex', 'nombre' => 'Kardex', 'cadencia' => 'Diario 00:05'],
            ['modulo' => 'ventas', 'nombre' => 'Ventas', 'cadencia' => 'Diario 00:00'],
        ])->map(function (array $m) use ($configs): array {
            $config = $configs->get($m['modulo']);
            $ultima = $this->ultimaCorrida($m['modulo']);
            $activas = $this->corridasActivas($m['modulo']);

            return [
                ...$m,
                'activo' => $config ? (bool) $config->activo : true,
                'desactivado_por' => $config?->desactivado_por,
                'desactivado_en' => $config?->desactivado_en,
                'ultima' => $ultima,
                'activas' => $activas,
            ];
        })->all();
    }

    /** @return array{estado: ?string, fecha: ?string, detalle: string}|null */
    protected function ultimaCorrida(string $modulo): ?array
    {
        return match ($modulo) {
            'stock-actual' => $this->normalizar(StockCuadreSoporte::query()->latest('id')->first(), 'completado_at', fn ($r) => "{$r->cuadres_guardados} cuadres, {$r->detalles_guardados} detalles"),
            'salidas-stock' => $this->normalizar(SalidaStockSincronizacion::query()->latest('id')->first(), 'completado_en', fn ($r) => "{$r->cabeceras_guardadas} cabeceras, {$r->detalles_guardados} detalles"),
            'guias-internas' => $this->normalizar(GuiaInternaSincronizacion::query()->latest('id')->first(), 'completado_en', fn ($r) => "{$r->cabeceras_guardadas} cabeceras, {$r->detalles_guardados} detalles"),
            'requerimientos-stock' => $this->normalizar(RequerimientoStockSincronizacion::query()->latest('id')->first(), 'completado_en', fn ($r) => "{$r->cabeceras_guardadas} cabeceras, {$r->detalles_guardados} detalles"),
            'kardex' => $this->normalizar(KardexExtraccion::query()->latest('id')->first(), 'completado_at', fn ($r) => "{$r->locales_procesados}/{$r->locales_total} locales, {$r->movimientos_guardados} movimientos"),
            'ventas' => $this->normalizar(VentaExtraccion::query()->latest('id')->first(), 'completado_at', fn ($r) => "{$r->ventas_guardadas} ventas, {$r->items_guardados} ítems"),
            default => null,
        };
    }

    protected function normalizar($registro, string $fechaCampo, \Closure $detalle): ?array
    {
        if (! $registro) {
            return null;
        }

        return [
            'estado' => $registro->estado,
            'fecha' => $registro->{$fechaCampo}?->format('d/m/Y H:i') ?? $registro->updated_at?->format('d/m/Y H:i'),
            'detalle' => $detalle($registro),
        ];
    }

    protected function corridasActivas(string $modulo): int
    {
        return match ($modulo) {
            'stock-actual' => StockCuadreSoporte::whereIn('estado', ['pendiente', 'en_progreso'])->count(),
            'salidas-stock' => SalidaStockSincronizacion::whereIn('estado', ['pendiente', 'en_progreso'])->count(),
            'guias-internas' => GuiaInternaSincronizacion::whereIn('estado', ['pendiente', 'en_progreso'])->count(),
            'requerimientos-stock' => RequerimientoStockSincronizacion::whereIn('estado', ['pendiente', 'en_progreso'])->count(),
            'kardex' => KardexExtraccion::whereIn('estado', ['pendiente', 'en_progreso'])->count(),
            'ventas' => VentaExtraccion::whereIn('estado', ['pendiente', 'planificando', 'en_progreso'])->count(),
            default => 0,
        };
    }

    /** @return array<int, array<string, mixed>> */
    public function historialModulo(): array
    {
        if (! $this->moduloDetalle) {
            return [];
        }

        $rows = match ($this->moduloDetalle) {
            'stock-actual' => StockCuadreSoporte::query()->latest('id')->limit(20)->get()->map(fn ($r) => $this->filaHistorial($r, 'completado_at', "{$r->cuadres_guardados} cuadres / {$r->detalles_guardados} detalles")),
            'salidas-stock' => SalidaStockSincronizacion::query()->latest('id')->limit(20)->get()->map(fn ($r) => $this->filaHistorial($r, 'completado_en', "{$r->cabeceras_guardadas} cabeceras / {$r->detalles_guardados} detalles / {$r->errores} fallidas")),
            'guias-internas' => GuiaInternaSincronizacion::query()->latest('id')->limit(20)->get()->map(fn ($r) => $this->filaHistorial($r, 'completado_en', "{$r->cabeceras_guardadas} cabeceras / {$r->detalles_guardados} detalles / {$r->errores} fallidas")),
            'requerimientos-stock' => RequerimientoStockSincronizacion::query()->latest('id')->limit(20)->get()->map(fn ($r) => $this->filaHistorial($r, 'completado_en', "{$r->cabeceras_guardadas} cabeceras / {$r->detalles_guardados} detalles / {$r->errores} fallidas")),
            'kardex' => KardexExtraccion::query()->latest('id')->limit(20)->get()->map(fn ($r) => $this->filaHistorial($r, 'completado_at', "{$r->locales_procesados}/{$r->locales_total} locales / {$r->movimientos_guardados} movimientos")),
            'ventas' => VentaExtraccion::query()->latest('id')->limit(20)->get()->map(fn ($r) => $this->filaHistorial($r, 'completado_at', "{$r->ventas_guardadas} ventas / {$r->items_guardados} ítems / {$r->ventas_fallidas} fallidas")),
            default => collect(),
        };

        return $rows->all();
    }

    protected function filaHistorial($registro, string $fechaCampo, string $detalle): array
    {
        return [
            'id' => $registro->id,
            'estado' => $registro->estado,
            'fecha' => $registro->{$fechaCampo}?->format('d/m/Y H:i') ?? $registro->created_at?->format('d/m/Y H:i'),
            'detalle' => $detalle,
            'mensaje_error' => $registro->mensaje_error,
        ];
    }

    public function nombreModuloDetalle(): string
    {
        return collect($this->resumen())->firstWhere('modulo', $this->moduloDetalle)['nombre'] ?? '';
    }
}
