<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Tareas periódicas de producción (las ejecuta el servicio `worker`: `php artisan schedule:work`). Sin ellas los premios no llegan al host
// y las exportaciones en claro no descargadas no se destruyen (revisión P10, hallazgo 1).
Schedule::command('gamification:outbox:dispatch')->everyTenSeconds()->withoutOverlapping(5);
Schedule::command('admin:purge-exports')->everyMinute()->withoutOverlapping(5);
