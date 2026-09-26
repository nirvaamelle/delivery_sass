<?php

use App\Domain\Ops\JobFailureAlerts;
use App\Jobs\ComputePayrollRun;
use App\Models\JobFailureAlert;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Str;

use function Pest\Laravel\artisan;

/*
|--------------------------------------------------------------------------
| Failed-job alerting and log rotation — P6-06
|--------------------------------------------------------------------------
|
| PHASE-PLAN.md Phase 6: "Horizon and failed-job alerting. Log rotation."
|
| **A failed job nobody hears about is a payroll nobody ran.** `ComputePayrollRun`
| is the build's first queued job, and when it fails the symptom is not an error
| on anybody's screen — it is a register that never appears, noticed on payday.
| Laravel writes the failure to `failed_jobs` and tells nobody. So a failure
| raises an alert, addressed to a role, that stays open until a NAMED person
| acknowledges it: F13's escalation pattern, for the same reason F13 needed it.
|
| **Alerting must not be able to break the thing it reports on.** The listener
| runs inside the queue worker's failure path; an exception there would take the
| worker down with it. It swallows its own failure and logs, deliberately.
|
| **Rotation, because an unrotated log is an outage with a delay.** The default
| `single` channel grows until the disk is full, and a full disk stops MySQL
| before it stops anything else.
|
| Horizon itself needs `ext-pcntl`, `ext-posix` and Redis, none of which exist on
| this machine — so it is configured in the runbook and marked blocked, not
| claimed.
|
*/

it('raises an alert when a queued job fails', function () {
    event(failedPayrollJob('Deadlock found when trying to get lock'));

    $alert = JobFailureAlert::query()->sole();

    expect($alert->job_name)->toBe(ComputePayrollRun::class)
        ->and($alert->exception_message)->toContain('Deadlock')
        ->and($alert->acknowledged_at)->toBeNull();
});

it('addresses the alert to the role that can act on it', function () {
    // An alert addressed to "the system" is read by nobody. Payroll is Finance's.
    event(failedPayrollJob('Timeout'));

    expect(JobFailureAlert::query()->sole()->addressed_to_role)->toBe('finance-manager');
});

it('addresses a job it has no owner for to the administrator, not to nobody', function () {
    event(failedJobNamed('App\\Jobs\\SomethingNew', 'Boom'));

    expect(JobFailureAlert::query()->sole()->addressed_to_role)->toBe('admin');
});

it('does not raise a second alert for the same job failing again before anybody looked', function () {
    // A worker retrying a broken job every minute would otherwise bury the one
    // alert that matters under sixty identical ones by lunchtime.
    event(failedPayrollJob('Timeout'));
    event(failedPayrollJob('Timeout'));

    expect(JobFailureAlert::query()->count())->toBe(1)
        ->and(JobFailureAlert::query()->sole()->occurrences)->toBe(2);
});

it('raises a fresh alert once the previous one was acknowledged', function () {
    // Acknowledged means "somebody fixed it". Failing again afterwards is news.
    event(failedPayrollJob('Timeout'));
    alerts()->acknowledge(JobFailureAlert::query()->sole(), User::factory()->create(), 'Restarted the worker.');

    event(failedPayrollJob('Timeout'));

    expect(JobFailureAlert::query()->count())->toBe(2);
});

it('acknowledges an alert and names who did', function () {
    event(failedPayrollJob('Timeout'));
    $finance = User::factory()->create();

    $alert = alerts()->acknowledge(JobFailureAlert::query()->sole(), $finance, 'Re-queued run PR-2026-00012.');

    expect((int) $alert->acknowledged_by_user_id)->toBe($finance->getKey())
        ->and($alert->resolution)->toBe('Re-queued run PR-2026-00012.');
});

it('refuses an acknowledgement that says nothing about what was done', function () {
    event(failedPayrollJob('Timeout'));

    expect(fn () => alerts()->acknowledge(JobFailureAlert::query()->sole(), User::factory()->create(), '  '))
        ->toThrow(DomainException::class, 'what was done');
});

it('refuses to acknowledge an alert twice', function () {
    event(failedPayrollJob('Timeout'));
    $alert = JobFailureAlert::query()->sole();
    alerts()->acknowledge($alert, User::factory()->create(), 'Fixed.');

    expect(fn () => alerts()->acknowledge($alert->fresh(), User::factory()->create(), 'Fixed again.'))
        ->toThrow(DomainException::class, 'already acknowledged');
});

it('refuses an acknowledged row with nobody against it, at the database', function () {
    event(failedPayrollJob('Timeout'));

    expect(fn () => JobFailureAlert::query()->whereKey(JobFailureAlert::query()->sole()->getKey())->update([
        'acknowledged_at' => now(),
        'acknowledged_by_user_id' => null,
        'resolution' => 'Nobody in particular.',
    ]))->toThrow(QueryException::class);
});

it('never lets a failure inside the alerting take the worker down', function () {
    // The listener runs in the worker's own failure path. An exception there
    // would turn one failed job into a dead queue.
    //
    // The alert service is swapped for one whose write genuinely throws, so this
    // fails if the catch in `recordSafely` is removed. An earlier version fed it
    // bad config instead — which `ownerOf` tolerates, so the catch was never
    // reached and the test proved nothing.
    app()->instance(JobFailureAlerts::class, new class extends JobFailureAlerts
    {
        public function record(JobFailed $event): JobFailureAlert
        {
            throw new RuntimeException('The alerts table is unavailable.');
        }
    });

    expect(fn () => event(failedPayrollJob('Timeout')))->not->toThrow(Throwable::class)
        ->and(JobFailureAlert::query()->count())->toBe(0);
});

it('exits non-zero from the console while an alert is open', function () {
    // So a cron line or an uptime monitor can ask, without anybody logging in.
    event(failedPayrollJob('Timeout'));

    artisan('ops:job-failures')->assertExitCode(1);
});

it('exits zero from the console when nothing is open', function () {
    artisan('ops:job-failures')->assertExitCode(0);
});

/*
|--------------------------------------------------------------------------
| Rotation and pruning
|--------------------------------------------------------------------------
*/

it('rotates the application log daily rather than growing one file forever', function () {
    expect(config('logging.channels.stack.channels'))->toContain('daily')
        ->and(config('logging.channels.stack.channels'))->not->toContain('single');
});

it('keeps a bounded number of rotated log files', function () {
    // Enough to investigate last month's incident; not so many the disk fills.
    expect((int) config('logging.channels.daily.max_files'))->toBeGreaterThanOrEqual(14)
        ->and((int) config('logging.channels.daily.max_files'))->toBeLessThanOrEqual(90);
});

it('schedules pruning of old failed jobs', function () {
    expect(scheduledCommandLine())->toContain('queue:prune-failed');
});

it('schedules the rehearsed restore monthly', function () {
    // P6-04's rule — a restore verified in March is not a restore verified —
    // written into the scheduler rather than into somebody's memory.
    expect(scheduledCommandLine())->toContain('backup:rehearse');
});

it('does not schedule anything that prunes the activity log', function () {
    // PLAN.md §3 makes it append-only. A tidy-up job that deleted old entries
    // would quietly shorten the audit trail B4 reads.
    expect(scheduledCommandLine())->not->toContain('activitylog:clean');
});

/**
 * Every scheduled command, as one searchable string.
 *
 * Matched by substring rather than by array element: the scheduler stores the
 * full invocation — PHP binary path, `artisan`, then the arguments — so an exact
 * match against `queue:prune-failed` never succeeds even when it is scheduled.
 */
function scheduledCommandLine(): string
{
    return collect(app(Schedule::class)->events())
        ->map(fn ($event): string => (string) $event->command)
        ->implode('
');
}

function alerts(): JobFailureAlerts
{
    return app(JobFailureAlerts::class);
}

function failedPayrollJob(string $message): JobFailed
{
    return failedJobNamed(ComputePayrollRun::class, $message);
}

function failedJobNamed(string $class, string $message): JobFailed
{
    // A real SyncJob with a real payload, not a mock. It resolves its name from
    // the payload's `displayName` exactly as a queue worker's job does, so the
    // listener is exercised against the object it will actually receive.
    $payload = json_encode([
        'uuid' => (string) Str::uuid(),
        'displayName' => $class,
        'job' => 'Illuminate\Queue\CallQueuedHandler@call',
        'data' => ['commandName' => $class, 'command' => ''],
    ], JSON_THROW_ON_ERROR);

    return new JobFailed('database', new SyncJob(app(), $payload, 'database', 'default'), new RuntimeException($message));
}
