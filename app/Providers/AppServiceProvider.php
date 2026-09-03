<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Defensa contra el bug de timestamps encontrado el 2026-09-03: una
        // corrida de Guías internas real (#71) escribió `updated_at` ~5h
        // atrasado (Lima vs UTC) durante horas, sin que se haya podido
        // confirmar la causa raíz exacta de por qué esa conexión persistente
        // perdió el `SET TIME ZONE` que PostgresConnector::configureTimezone()
        // ya aplica al ABRIR la conexión (config/database.php). Un worker de
        // colas (`queue:work`) reutiliza la MISMA conexión PDO durante toda su
        // vida (hasta 1h, por --max-time=3600) a través de docenas de jobs, así
        // que si esa conexión llega a quedar en el timezone equivocado por
        // cualquier motivo, se queda así el resto de esa hora. Reafirmar la
        // zona horaria de la sesión antes de CADA job es barato y cierra el
        // hueco sin depender de encontrar la causa original.
        Queue::before(function (): void {
            try {
                DB::statement("set time zone '".config('app.timezone')."'");
            } catch (\Throwable) {
                // No debe tumbar el job por esto -- es una reafirmación
                // defensiva, no un requisito para que el job funcione.
            }
        });
    }
}
