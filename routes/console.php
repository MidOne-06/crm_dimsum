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

// Mantiene la copia local de Stock Actual al día sin bloquear a los usuarios.
Schedule::command('stock-actual:sincronizar --directo')
  ->everyThirtyMinutes()
  ->withoutOverlapping(180);

Schedule::command('salidas-stock:sincronizar')
  ->hourly()
  ->withoutOverlapping(180);

Schedule::command('guias-internas:sincronizar')
  ->everyThirtyMinutes()
  ->withoutOverlapping(180);
