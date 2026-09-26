<?php

namespace App\Console\Commands;

use App\Domain\Audit\ActivityLogExporter;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * `php artisan activity:export --from=… --to=…`
 *
 * The way this will actually be used: an auditor asks, and somebody runs it on
 * the server. An export reachable only through a screen is one that needs a
 * person with a login and a browser on the box.
 */
class ExportActivityLog extends Command
{
    protected $signature = 'activity:export
                            {--from= : First day of the period, inclusive (YYYY-MM-DD)}
                            {--to= : Last day of the period, inclusive (YYYY-MM-DD)}';

    protected $description = 'Export the activity log for a period to a CSV on the private disk';

    public function handle(ActivityLogExporter $exporter): int
    {
        $from = $this->option('from');
        $to = $this->option('to');

        if (! is_string($from) || ! is_string($to) || $from === '' || $to === '') {
            // Deliberately no default. "Everything" is the request easiest to
            // make by accident and hardest to produce.
            $this->error('Both --from and --to are required. An auditor asks for a period.');

            return self::FAILURE;
        }

        try {
            $path = $exporter->export(Carbon::parse($from), Carbon::parse($to));
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Activity log written to %s on the private disk.', $path));

        return self::SUCCESS;
    }
}
