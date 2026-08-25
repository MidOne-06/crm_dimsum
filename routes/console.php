<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('opm:catalogo:sincronizar')
    ->dailyAt((string) env('OPM_CATALOG_SYNC_TIME', '03:15'))
    ->timezone((string) config('app.timezone'))
    ->withoutOverlapping(30);

Schedule::command('ventas:procesar-automatizaciones')
    ->everyMinute()
    ->withoutOverlapping();
