<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:send-upcoming-reservation-reminders')->hourly()->onOneServer()->withoutOverlapping();
Schedule::command('billing:expire-subscriptions')->daily()->onOneServer()->withoutOverlapping();
Schedule::command('horizon:snapshot')->everyFiveMinutes()->onOneServer()->withoutOverlapping();
Schedule::command('queue:monitor redis:default,redis:notifications,redis:realtime --max='.(int) config('performance.monitoring.queue_backlog'))
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();
Schedule::command('db:monitor --databases='.(string) config('database.default').' --max='.(int) config('performance.monitoring.database_connections'))
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();
