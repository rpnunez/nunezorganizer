<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Scheduler (run `php artisan schedule:run` every minute from cron, or `schedule:work` locally).
*/
Schedule::command('nunez:mark-unanswered')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('nunez:dispatch-due')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('nunez:recover-deliveries')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('nunez:dispatch-reviews')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();
