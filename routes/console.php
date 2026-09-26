<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| The operational schedule — P6-06
|--------------------------------------------------------------------------
|
| What keeps Phase 6's guarantees true after go-live rather than true once.
|
| Deliberately absent: anything that prunes `activity_log`. PLAN.md §3 makes
| it append-only, and a tidy-up job that deleted old entries would quietly
| shorten the audit trail B4 reads. A test asserts it stays absent.
|
*/

// Failed jobs older than a fortnight are noise in `failed_jobs`. The ALERT for
// each one outlives this, in `job_failure_alerts`, until somebody signs it off.
Schedule::command('queue:prune-failed --hours=336')->daily();

// P6-04's rule: a restore verified in March is not a restore verified.
Schedule::command('backup:rehearse --scratch=construction_scratch')
    ->monthlyOn(1, '02:00')
    ->withoutOverlapping();

// Nobody should find out about a failed payroll run on payday. Hourly, so an
// uptime monitor watching the exit code hears within the hour.
Schedule::command('ops:job-failures')->hourly();
