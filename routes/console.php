<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Scheduler. A single cron entry runs everything:
|   * * * * * cd /var/www/email-marketing && php artisan schedule:run >> /dev/null 2>&1
| onOneServer() uses the Redis cache lock, so a second app server will not double-run tasks.
*/

// Start campaigns whose scheduled time has arrived.
Schedule::command('campaigns:dispatch-scheduled')->everyMinute()->withoutOverlapping(5)->onOneServer();

// Safety net for progress counters (live updates already happen after every batch / webhook).
Schedule::command('campaigns:refresh-stats')->everyMinute()->withoutOverlapping(5)->onOneServer();

// Close out crashed sends and re-queue lost batches.
Schedule::command('campaigns:recover-stale')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();

// Trash retention: permanently delete campaigns trashed more than 7 days ago.
Schedule::command('campaigns:purge-trash')->dailyAt('02:30')->onOneServer();

// Expired temporary data (uploaded CSVs, old import reports, old events).
Schedule::command('data:cleanup')->dailyAt('03:15')->onOneServer();

// Failed-job and queue housekeeping.
Schedule::command('queue:prune-failed --hours=720')->daily()->onOneServer();
Schedule::command('queue:prune-batches --hours=48')->daily()->onOneServer();
