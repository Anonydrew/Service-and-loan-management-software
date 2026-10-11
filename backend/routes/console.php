<?php

use Illuminate\Support\Facades\Schedule;

// Requires the scheduler: `* * * * * php artisan schedule:run` (see docs/DEPLOYMENT.md).
Schedule::command('loans:process-daily')->dailyAt('00:30')->withoutOverlapping()->onOneServer();
Schedule::command('biometrics:purge-expired')->dailyAt('02:00')->withoutOverlapping()->onOneServer();
