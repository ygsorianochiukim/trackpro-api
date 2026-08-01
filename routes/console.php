<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
| Needs a single cron entry on the host:
|   * * * * * cd /path/to/trackpro-api && php artisan schedule:run >> /dev/null 2>&1
*/

// Raise yearly renewal invoices and flip lapsed subscriptions to past_due/expired.
Schedule::command('subscriptions:bill')->dailyAt('02:00')->withoutOverlapping();

// Safety net for payments PayMongo took but we never got a webhook for. The
// account pages also reconcile on read, so this mainly covers customers who pay
// and never come back to the site.
Schedule::command('subscriptions:reconcile')->everyThirtyMinutes()->withoutOverlapping();
