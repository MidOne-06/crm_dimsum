<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\VentaExtraccionAutomatizacion;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class AutomatizarFaltantesVentas2026 extends Command
{
    protected $signature = 'ventas:automatizar-faltantes-2026 {--usuario= : Correo del usuario que inicia el proceso}';
    protected $description = 'Crea el plan recuperable para extraer los periodos 2026 aún no cubiertos.';

    public function handle(): int
    {
        if (VentaExtraccionAutomatizacion::query()->whereIn('estado', ['pendiente', 'en_progreso'])->exists()) {
            $this->error('Ya existe una automatización de ventas activa.');

            return self::FAILURE;
        }

        $localesCompletos = '1-2-3-4-6-7-8-9-10-11-12-13-14-15-16-17-18-19-20-21-22-23-24-25-26-27-28-29-30-31-32-33-34-35-36-37-38';
        $localesSinUno = '2-3-4-6-7-8-9-10-11-12-13-14-15-16-17-18-19-20-21-22-23-24-25-26-27-28-29-30-31-32-33-34-35-36-37-38';
        $segmentos = [];

        // Plan recalculado tras las corridas #2 a #8: no incluye enero,
        // febrero ni los días ya consolidados de marzo a agosto.
        $hastaHoy = Carbon::today()->min(Carbon::create(2026, 8, 31))->toDateString();
        foreach ([['2026-03-08', '2026-04-30'], ['2026-05-05', '2026-06-04'], ['2026-06-08', '2026-07-26'], ['2026-08-01', '2026-08-11'], ['2026-08-20', $hastaHoy]] as [$inicio, $fin]) {
            for ($cursor = Carbon::parse($inicio), $end = Carbon::parse($fin); $cursor->lte($end); $cursor->addDays(33)) {
                $hasta = $cursor->copy()->addDays(32)->min($end);
                $segmentos[] = $this->segmento($cursor->toDateString(), $hasta->toDateString(), $localesCompletos);
            }
        }

        // FABRICA ya fue extraída del 13 al 19/08; los demás locales no.
        $segmentos[] = $this->segmento('2026-08-13', '2026-08-19', $localesSinUno);
        usort($segmentos, fn (array $a, array $b) => $a['filtros']['fechaInicio'] <=> $b['filtros']['fechaInicio']);

        $usuario = filled($this->option('usuario'))
            ? User::where('email', $this->option('usuario'))->value('id')
            : User::where('is_active', true)->orderBy('id')->value('id');

        $automatizacion = VentaExtraccionAutomatizacion::create([
            'estado' => 'pendiente',
            'segmentos' => $segmentos,
            'iniciado_por' => $usuario,
        ]);

        $this->info("Automatización #{$automatizacion->id} creada con ".count($segmentos).' bloques.');
        Artisan::call('ventas:procesar-automatizaciones');
        $this->line(trim(Artisan::output()));

        return self::SUCCESS;
    }

    private function segmento(string $inicio, string $fin, string $locales): array
    {
        return [
            'estado' => 'pendiente',
            'filtros' => [
                'locales' => $locales,
                'moneda' => '1',
                'comprobante' => '-1',
                'estado' => '1',
                'orden' => '1',
                'fechaInicio' => $inicio,
                'fechaFin' => $fin,
            ],
        ];
    }
}
