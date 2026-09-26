<?php

namespace App\Console\Commands;

use App\Domain\Ops\JobFailureAlerts;
use Illuminate\Console\Command;

/**
 * `php artisan ops:job-failures`
 *
 * Exits non-zero while any job failure is unacknowledged, so a cron line or an
 * uptime monitor can ask the question without anybody logging in — which,
 * until Horizon is running on a server, is the only failed-job visibility that
 * does not depend on somebody remembering to look.
 */
class JobFailuresStatus extends Command
{
    protected $signature = 'ops:job-failures';

    protected $description = 'List queued job failures nobody has acknowledged';

    public function handle(JobFailureAlerts $alerts): int
    {
        $open = $alerts->open();

        if ($open->isEmpty()) {
            $this->info('No unacknowledged job failures.');

            return self::SUCCESS;
        }

        $this->error(sprintf('%d unacknowledged job failure(s):', $open->count()));

        $this->table(
            ['Job', 'For', 'Times', 'First failed', 'Last error'],
            $open->map(fn ($alert): array => [
                (string) $alert->job_name,
                (string) $alert->addressed_to_role,
                (string) $alert->occurrences,
                $alert->first_failed_at->toDateTimeString(),
                mb_strimwidth((string) $alert->exception_message, 0, 60, '…'),
            ])->all(),
        );

        return self::FAILURE;
    }
}
