<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('backup:run')->dailyAt('02:00');
Schedule::command('backup:cleanup')->dailyAt('03:00');
Schedule::command('performance:calculate-credits')->monthlyOn(1, '06:00');
Schedule::command('drivers:check-expiries')->dailyAt('07:00');

// TrackSolid GPS poll. The scheduler ticks every 60 seconds, so we run
// the command twice per minute and let the command itself enforce the
// per-poll interval set in the integrations settings page (see
// `tracksolid_poll_interval_seconds`). The command no-ops cheaply when
// the integration is disabled, which makes this safe to keep on in
// dev / CI environments without creds.
Schedule::command('tracksolid:poll')
    ->everyThirtySeconds()
    ->withoutOverlapping(2)
    ->runInBackground();
