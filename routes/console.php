<?php

use App\Jobs\QueueHeartbeat;
use App\Support\Health;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('zonseo:run-app-schedules')->dailyAt('02:00')->withoutOverlapping()->onOneServer();
Schedule::command('zonseo:send-sms-reminders')->dailyAt('09:00')->withoutOverlapping()->onOneServer();
Schedule::command('zonseo:send-plan-reminders')->dailyAt('08:00')->withoutOverlapping()->onOneServer();

// Monitoring, backups and housekeeping (see docs/03-DEPLOYMENT.md).
Schedule::call(fn () => Health::beat('scheduler'))->name('scheduler-heartbeat')->everyMinute();
Schedule::job(new QueueHeartbeat)->everyFiveMinutes();
Schedule::command('zonseo:monitor')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('zonseo:backup')->dailyAt('01:30')->withoutOverlapping()->onOneServer();
Schedule::command('queue:prune-failed --hours=720')->daily();
Schedule::command('auth:clear-resets')->daily();
Schedule::command('activitylog:clean')->weekly();
