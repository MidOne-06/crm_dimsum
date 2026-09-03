<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\VentaExtraccion;
use App\Models\VentaExtraccionAutomatizacion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Ventas era el único módulo de los 5 (Guías/Salidas/Requerimientos/Stock
 * Actual/Kardex ya tenían el suyo) sin ninguna sincronización incremental
 * programada -- `ventas:procesar-automatizaciones` corre cada minuto, pero
 * solo AVANZA una automatización ya creada; nada la creaba sola día a día.
 * Encontrado en la auditoría de cobertura del 2026-09-03: el hueco real
 * llegaba hasta 2026-07-22 porque nadie había vuelto a encolar un bloque
 * nuevo desde el último backfill manual.
 *
 * Mismo patrón que kardex:sincronizar-diario: ventana corta con margen de
 * solape (3 días), todos los locales, no dispara si ya hay algo en curso.
 */
class SincronizarVentasDiario extends Command
{
    protected $signature = 'ventas:sincronizar-diario {--dias=3 : Días hacia atrás a re-cubrir, con margen de solape}';

    protected $description = 'Crea y despacha automáticamente el bloque diario de extracción de Ventas para todos los locales.';

    private const LOCALES_TODOS = '1-2-3-4-6-7-8-9-10-11-12-13-14-15-16-17-18-19-20-21-22-23-24-25-26-27-28-29-30-31-32-33-34-35-36-37-38';

    public function handle(): int
    {
        if (VentaExtraccionAutomatizacion::query()->whereIn('estado', ['pendiente', 'en_progreso'])->exists()) {
            $this->info('Ya hay una automatización de Ventas activa; se reintentará en el próximo ciclo.');

            return self::SUCCESS;
        }

        if (VentaExtraccion::query()->whereIn('estado', ['pendiente', 'planificando', 'en_progreso'])->exists()) {
            $this->info('Ya hay una extracción de Ventas activa; se reintentará en el próximo ciclo.');

            return self::SUCCESS;
        }

        $dias = max(1, (int) $this->option('dias'));
        $desde = now()->subDays($dias)->toDateString();
        $hasta = now()->toDateString();

        $automatizacion = VentaExtraccionAutomatizacion::create([
            'estado' => 'pendiente',
            'segmentos' => [[
                'estado' => 'pendiente',
                'filtros' => [
                    'locales' => self::LOCALES_TODOS, 'moneda' => '1', 'comprobante' => '-1',
                    'estado' => '1', 'orden' => '1',
                    'fechaInicio' => $desde, 'fechaFin' => $hasta,
                ],
            ]],
            'iniciado_por' => User::where('is_active', true)->orderBy('id')->value('id'),
        ]);

        $this->info("Ventas: automatización diaria #{$automatizacion->id} creada ({$desde} a {$hasta}, todos los locales).");
        Artisan::call('ventas:procesar-automatizaciones');
        $this->line(trim(Artisan::output()));

        return self::SUCCESS;
    }
}
