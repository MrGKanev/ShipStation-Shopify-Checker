<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Spatie\Health\Models\HealthCheckResultHistoryItem;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('activitylog:clean')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('health:schedule-check-heartbeat')->everyMinute();
Schedule::command('health:queue-check-heartbeat')->everyMinute();
Schedule::command('health:check')->everyMinute()->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [HealthCheckResultHistoryItem::class]])->dailyAt('02:45');
