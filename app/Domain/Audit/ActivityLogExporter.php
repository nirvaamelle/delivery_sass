<?php

namespace App\Domain\Audit;

use App\Domain\Projects\ProjectScope;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/**
 * The audit trail, extractable — PHASE-PLAN.md Phase 6, and the practical half
 * of PLAN.md §3's append-only `activity_log`.
 *
 * That section made the log immutable because "this system produces an audited
 * P&L, so who changed what and when is not optional". An audit trail nobody can
 * get out of the database satisfies that only in principle: B4's whole point is
 * that somebody outside the company reads it.
 *
 * **A file, not a screen.** An auditor works from a spreadsheet they can
 * filter, keep and attach to a working paper. Paginating three years of entries
 * through a browser is a way of not producing them.
 *
 * **The private disk.** Every row names a person and what they did; the public
 * disk would make the company's audit trail a URL. Same rule as the payslips
 * and the disbursement file.
 *
 * **Every value is neutralised against formula injection.** This file exists to
 * be opened in a spreadsheet, and a cell beginning `=`, `+`, `-` or `@` is
 * executed on open. Descriptions in this system are written by its users, so
 * without the guard a value typed into a punchlist becomes code running on the
 * machine of the person auditing the company. The value is prefixed rather than
 * stripped: the auditor must still see what was actually recorded, and deleting
 * characters from an audit trail to make it safe to read is worse than the
 * injection it prevents.
 *
 * **Unscoped, deliberately.** P6-01 restricts what a user may read of live
 * project data. This is the opposite job: a partial audit trail that looks
 * complete is worse than none, and an auditor asking for one project's entries
 * is not the case the export exists for.
 */
class ActivityLogExporter
{
    /**
     * The columns, in a fixed order.
     *
     * Fixed because a header that moves between exports cannot be compared to
     * the file filed last quarter, which is most of what an auditor does with
     * it. Adding a column goes on the end.
     *
     * @var array<int, string>
     */
    public const COLUMNS = [
        'logged_at',
        'log_name',
        'event',
        'description',
        'subject_type',
        'subject_id',
        'causer_name',
        'causer_email',
        'changes',
    ];

    /**
     * Write the log for a period and return its path on the private disk.
     *
     * Both ends inclusive: somebody will ask for a calendar month, and an
     * export that dropped the 30th would be wrong in the direction nobody
     * checks.
     *
     * @throws DomainException when the range ends before it starts
     */
    public function export(CarbonInterface $from, CarbonInterface $to, ?string $path = null): string
    {
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();

        if ($end->lessThan($start)) {
            throw new DomainException(sprintf(
                'The range ends before it starts (%s to %s). An inverted period matches nothing, and an empty export reads as "nothing happened".',
                $from->toDateString(),
                $to->toDateString(),
            ));
        }

        $path ??= sprintf(
            'exports/activity-log/activity-log-%s-to-%s-%s.csv',
            $start->toDateString(),
            $end->toDateString(),
            now()->format('YmdHis'),
        );

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new DomainException('Could not open a buffer to write the export into.');
        }

        fputcsv($handle, self::COLUMNS);

        // Unscoped: see the class docblock. Chunked because the whole point of
        // this class is a period long enough that loading it into memory is the
        // thing that fails on the day it is needed.
        ProjectScope::withoutScope(function () use ($start, $end, $handle): void {
            Activity::query()
                ->with(['causer'])
                ->whereBetween('created_at', [$start, $end])
                ->orderBy('created_at')
                ->orderBy('id')
                ->chunk(500, function ($activities) use ($handle): void {
                    foreach ($activities as $activity) {
                        fputcsv($handle, $this->row($activity));
                    }
                });
        });

        rewind($handle);
        $contents = (string) stream_get_contents($handle);
        fclose($handle);

        Storage::disk('local')->put($path, $contents);

        return $path;
    }

    /**
     * Characters a spreadsheet treats as the start of a formula.
     *
     * Tab and carriage return are here because Excel strips leading whitespace
     * before deciding: "	=cmd" is still a formula to it, and a guard that only
     * looked at the first character would be walked straight past.
     *
     * @var array<int, string>
     */
    private const FORMULA_LEADS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Make a value safe to open in a spreadsheet without changing what it says.
     *
     * Public because it is worth testing directly: this is a security control,
     * and one reachable only through a whole export is one nobody exercises at
     * its boundaries.
     */
    public function neutralise(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        // Read against the value as a WHOLE, not the first character alone: a
        // negative amount begins with a minus and is not a formula, so the test
        // is whether what follows could be evaluated.
        if (! in_array($value[0], self::FORMULA_LEADS, true)) {
            return $value;
        }

        if (preg_match('/^[+-]?[\d,.]+$/', $value) === 1) {
            // A plain number, negative or not. Prefixing it would make every
            // amount in the export a text cell.
            return $value;
        }

        return "\t".$value;
    }

    /**
     * @return array<int, string>
     */
    private function row(Activity $activity): array
    {
        $causer = $activity->causer;

        return array_map(fn (string $value): string => $this->neutralise($value), [
            (string) $activity->created_at?->toDateTimeString(),
            (string) $activity->log_name,
            (string) $activity->event,
            (string) $activity->description,
            // The class name as written, not a prettified label: an auditor
            // tracing a row back needs the thing the database calls it.
            (string) $activity->subject_type,
            (string) $activity->subject_id,
            $causer?->getAttribute('name') ?? '',
            $causer?->getAttribute('email') ?? '',
            // What actually changed, as JSON. "Something was updated" is not
            // evidence of anything.
            $this->changes($activity),
        ]);
    }

    private function changes(Activity $activity): string
    {
        $properties = $activity->properties;

        if ($properties->isEmpty()) {
            return '';
        }

        return (string) json_encode($properties->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
