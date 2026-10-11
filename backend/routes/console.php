<?php

use Illuminate\Support\Facades\Schedule;

// On a VPS: one cron entry `* * * * * php artisan schedule:run` runs both.
// On Hostinger shared/Business hosting proc_open is disabled, which the
// scheduler needs, so each command gets its own daily cron job instead
// (see docs/DEPLOY-HOSTINGER.md, step 10). Both commands are safe to re-run.
Schedule::command('loans:process-daily')->dailyAt('00:30')->withoutOverlapping()->onOneServer();
Schedule::command('biometrics:purge-expired')->dailyAt('02:00')->withoutOverlapping()->onOneServer();
