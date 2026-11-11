<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Tareas periódicas de producción (las ejecuta el servicio `worker`: `php artisan schedule:work`). Sin ellas los premios no llegan al host
// y las exportaciones en claro no descargadas no se destruyen (revisión P10, hallazgo 1).
$outboxEvery = [1 => 'everySecond', 2 => 'everyTwoSeconds', 5 => 'everyFiveSeconds', 10 => 'everyTenSeconds', 15 => 'everyFifteenSeconds', 20 => 'everyTwentySeconds', 30 => 'everyThirtySeconds'];
$outboxEvent = Schedule::command('gamification:outbox:dispatch');
$outboxEvent->{$outboxEvery[(int) config('gamification.outbox.dispatch_interval_seconds')] ?? 'everyTenSeconds'}()->withoutOverlapping(5);
Schedule::command('admin:purge-exports')->everyMinute()->withoutOverlapping(5);

// Muestra real (SPEC-006): caducidad y corridas de sembrado/reinicio pedidas desde el panel. Solo en servidores declarados de demostración.
Schedule::command('gamification:demo:process')->everyMinute()->withoutOverlapping(60)->when(fn (): bool => (bool) config('gamification.demo.allowed'));
