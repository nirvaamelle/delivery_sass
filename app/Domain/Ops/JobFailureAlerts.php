<?php

namespace App\Domain\Ops;

use App\Models\JobFailureAlert;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Failed-job alerting — P6-06.
 *
 * **A failed job nobody hears about is a payroll nobody ran.** Laravel records
 * the failure in `failed_jobs` and tells nobody; the symptom of a failed
 * `ComputePayrollRun` is a register that never appears, noticed on payday. So a
 * failure raises an alert addressed to the role that can act, and it stays open
 * until a named person says what they did — F13's escalation pattern.
 *
 * **Alerting must not be able to break the thing it reports on.** `record` runs
 * inside the queue worker's own failure path, where an exception would turn one
 * failed job into a dead worker. `recordSafely` is what the listener calls, and
 * it swallows its own failure — logged as a warning, because an alerting
 * failure is worth knowing about and not worth taking the queue down for.
 */
class JobFailureAlerts
{
    /**
     * The listener's entry point. Never throws.
     */
    public function recordSafely(JobFailed $event): void
    {
        try {
            $this->record($event);
        } catch (Throwable $e) {
            Log::warning('Could not raise a job failure alert.', [
                'job' => $this->nameOf($event),
                'alerting_error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Raise an alert, or fold a repeat into the one still open.
     */
    public function record(JobFailed $event): JobFailureAlert
    {
        $name = $this->nameOf($event);

        $open = JobFailureAlert::query()
            ->where('job_name', $name)
            ->whereNull('acknowledged_at')
            ->first();

        if ($open !== null) {
            // Same job, nobody has looked yet: one alert that says how often,
            // rather than sixty that say the same thing.
            $open->update([
                'occurrences' => $open->occurrences + 1,
                'last_failed_at' => now(),
                'exception_message' => $event->exception->getMessage(),
            ]);

            return $open->refresh();
        }

        return JobFailureAlert::query()->create([
            'job_name' => $name,
            'connection' => $event->connectionName,
            'queue' => $event->job->getQueue(),
            'exception_message' => $event->exception->getMessage(),
            'addressed_to_role' => $this->ownerOf($name),
            'occurrences' => 1,
            'first_failed_at' => now(),
            'last_failed_at' => now(),
        ]);
    }

    /**
     * Somebody has dealt with it.
     *
     * @throws DomainException when already acknowledged, or nothing is said
     *                         about what was done
     */
    public function acknowledge(JobFailureAlert $alert, User $by, string $resolution): JobFailureAlert
    {
        if ($alert->acknowledged_at !== null) {
            throw new DomainException(sprintf(
                'This alert was already acknowledged on %s.',
                $alert->acknowledged_at->toDateTimeString(),
            ));
        }

        if (trim($resolution) === '') {
            // "Acknowledged" with nothing else tells the next person on call
            // that somebody saw it, not whether the payroll ran.
            throw new DomainException('An acknowledgement must say what was done about the failure.');
        }

        $alert->update([
            'acknowledged_at' => now(),
            'acknowledged_by_user_id' => $by->getKey(),
            'resolution' => trim($resolution),
        ]);

        return $alert->refresh();
    }

    /**
     * @return Collection<int, JobFailureAlert>
     */
    public function open(): Collection
    {
        return JobFailureAlert::query()
            ->whereNull('acknowledged_at')
            ->orderBy('first_failed_at')
            ->get();
    }

    /**
     * The role that has to act on this job failing.
     *
     * Anything without a configured owner goes to the administrator. An alert
     * addressed to nobody is read by nobody, and a new job added next year will
     * not have been thought about here.
     */
    public function ownerOf(string $jobName): string
    {
        $owners = config('ops.job_owners', []);
        $fallback = (string) config('ops.default_job_owner', 'admin');

        if (! is_array($owners)) {
            return $fallback;
        }

        $owner = $owners[$jobName] ?? null;

        return is_string($owner) && $owner !== '' ? $owner : $fallback;
    }

    private function nameOf(JobFailed $event): string
    {
        return $event->job->resolveName();
    }
}
