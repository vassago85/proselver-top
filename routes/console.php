<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('backup:run')->dailyAt('02:00');
Schedule::command('backup:cleanup')->dailyAt('03:00');
Schedule::command('performance:calculate-credits')->monthlyOn(1, '06:00');
