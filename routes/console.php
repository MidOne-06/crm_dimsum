<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('ventas:procesar-automatizaciones')
    ->everyMinute()
    ->withoutOverlapping();

// IMPORTANTE: estos tres comandos tienen un rango histórico completo como
// valor por defecto de --desde (para poder invocarlos manualmente como
// backfill de una sola vez). Si el schedule los invoca SIN --desde, cada
// ejecución periódica intenta re-sincronizar años de datos desde cero, nunca
// converge dentro del intervalo, y una corrida muere sin dejar avance
// resumible en la siguiente -- exactamente lo que dejó guías internas y
// requerimientos con solo unos meses/días de histórico real pese a llevar
// tiempo "sincronizándose". Por eso aquí se fuerza siempre una ventana
// incremental corta (con margen de solape para correcciones tardías de
// Restaurant); el histórico profundo se cubre una sola vez con un backfill
// manual (--desde=<fecha real de inicio>), no con el ciclo periódico.
$ventanaIncremental = fn (int $dias): string => now()->subDays($dias)->toDateString();

// runInBackground() es obligatorio aquí: sin él, Laravel ejecuta el comando
// EN EL HILO del propio tick del scheduler y espera a que termine antes de
// revisar el resto de tareas programadas -- una corrida de guías/salidas que
// tarda 40+ minutos bloquearía, por ejemplo, "ventas:procesar-automatizaciones"
// (que debe correr cada minuto) durante todo ese tiempo. runInBackground()
// hace que el scheduler lance el proceso y siga de inmediato con las demás
// tareas sin esperarlo.

// Mantiene la copia local de Stock Actual al día sin bloquear a los usuarios.
Schedule::command('stock-actual:sincronizar --directo --desde='.$ventanaIncremental(3))
  ->everyThirtyMinutes()
  ->withoutOverlapping(180)
  ->runInBackground();

Schedule::command('salidas-stock:sincronizar --desde='.$ventanaIncremental(3))
  ->hourly()
  ->withoutOverlapping(180)
  ->runInBackground();

Schedule::command('guias-internas:sincronizar --desde='.$ventanaIncremental(3))
  ->everyThirtyMinutes()
  ->withoutOverlapping(180)
  ->runInBackground();

// Autocura corridas huérfanas: si el proceso de un backfill muere (sesión
// SSH cortada, servidor reiniciado) la fila queda en 'en_progreso' para
// siempre y nadie la reintenta. Cada 10 min se detectan corridas estancadas
// (sin avance en 10+ min) y se relanzan solas, usando BackgroundArtisan (ver
// App\Services\BackgroundArtisan -- Process::start() no sobrevive en este
// contenedor bajo ningún padre, comprobado empíricamente). Rápido por
// diseño (solo revisa y dispara), no necesita runInBackground().
Schedule::command('extracciones:reanudar-huerfanas')
  ->everyTenMinutes()
  ->withoutOverlapping(60);

// Arranca las corridas que un usuario encoló desde la web (botón "Iniciar
// extracción" de guías internas / requerimientos de stock). Las páginas de
// Filament SOLO crean la fila 'pendiente' -- el arranque real siempre pasa
// por aquí, con BackgroundArtisan. También es rápido (solo revisa y
// dispara), no necesita runInBackground().
Schedule::command('extracciones:despachar-pendientes')
  ->everyMinute()
  ->withoutOverlapping(50);
