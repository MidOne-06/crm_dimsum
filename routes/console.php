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

// Mantiene la copia local de Stock Actual al día sin bloquear a los usuarios.
Schedule::command('stock-actual:sincronizar --directo --desde='.$ventanaIncremental(3))
  ->everyThirtyMinutes()
  ->withoutOverlapping(180);

Schedule::command('salidas-stock:sincronizar --desde='.$ventanaIncremental(3))
  ->hourly()
  ->withoutOverlapping(180);

Schedule::command('guias-internas:sincronizar --desde='.$ventanaIncremental(3))
  ->everyThirtyMinutes()
  ->withoutOverlapping(180);

// Autocura corridas huérfanas: si el proceso de un backfill muere (sesión
// SSH cortada, servidor reiniciado) la fila queda en 'en_progreso' para
// siempre y nadie la reintenta. Cada 10 min se detectan corridas estancadas
// (sin avance en 10+ min) y se relanzan solas.
Schedule::command('extracciones:reanudar-huerfanas')
  ->everyTenMinutes()
  ->withoutOverlapping(60);
