<?php

namespace App\Domain\Hris;

use App\Models\DailyTimeRecord;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Timelog;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Timelogs, DTR validation, and the held day — P3-04 and P3-05.
 *
 * Slide 7: **"No timelog, no pay" — unvalidated days are held and paid in the
 * next cutoff once the site certifies them.**
 *
 * **Held is not the same as unpaid, and that is the whole task.** The obvious
 * implementation drops an uncertified day: it was not validated, so it is not
 * paid, and the run balances. But the person worked it. Dropping the day leaves
 * them short a fortnight with nothing on record saying why, and the correction
 * arrives weeks later as somebody typing an adjustment line with no evidence
 * behind it. Holding keeps the day, keeps its hours, and records which cutoff it
 * was held out of — so the next payslip can say "carried from 1–15 May" instead
 * of showing an extra day nobody can explain.
 *
 * `paid_in_period_end` is the other half: it is what stops a day being paid once
 * in its own period and again as a carry-over, which is the failure this
 * mechanic would introduce if it were built carelessly.
 *
 * **The import reports what it could not take.** A biometrics export that
 * silently skips unparseable rows is a payroll run missing days nobody knows
 * about, and the person who notices is the one who was not paid. Rejected rows
 * come back with their line numbers so the file can be fixed.
 */
class TimekeepingService
{
    public function __construct(
        private readonly HiringService $hiring,
    ) {}

    /**
     * Import a batch of punches.
     *
     * Rows are typed loosely on purpose: they come out of a biometrics export,
     * which is a file somebody else's device wrote. Declaring the keys as
     * guaranteed would be a claim about a CSV, and the defensive reads below
     * would then look like dead code rather than the reason the import can
     * report a bad row instead of dying on it.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{imported: int, rejected: array<int, array{line: int, employee_number: string, reason: string}>}
     */
    public function import(Project $project, array $rows, ?string $batchReference = null): array
    {
        $imported = 0;
        $rejected = [];

        foreach (array_values($rows) as $index => $row) {
            $line = $index + 1;

            try {
                $this->importRow($project, $row, $batchReference);
                $imported++;
            } catch (Throwable $e) {
                // Reported, never swallowed. The line number is what makes the
                // report actionable rather than merely alarming.
                $rejected[] = [
                    'line' => $line,
                    'employee_number' => (string) ($row['employee_number'] ?? ''),
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return ['imported' => $imported, 'rejected' => $rejected];
    }

    /**
     * The site certifies a day.
     *
     * @throws DomainException when the day was already decided
     */
    public function validate(DailyTimeRecord $record, User $by, ?string $remarks = null): DailyTimeRecord
    {
        if (in_array($record->status, [DtrStatus::Validated, DtrStatus::Paid, DtrStatus::Rejected], true)) {
            throw new DomainException(sprintf(
                'The day %s for employee %d is already %s.',
                $record->work_date->toDateString(),
                $record->employee_id,
                $record->status->value,
            ));
        }

        $record->update([
            'status' => DtrStatus::Validated,
            'validated_at' => now(),
            'validated_by_user_id' => $by->getKey(),
            'remarks' => $remarks ?? $record->remarks,
        ]);

        return $record->refresh();
    }

    /**
     * The day did not happen as recorded.
     *
     * A third outcome, deliberately not a shade of held: a day that never
     * happened must not carry forward waiting to be certified.
     *
     * @throws DomainException when no reason is given
     */
    public function reject(DailyTimeRecord $record, User $by, string $reason): DailyTimeRecord
    {
        if (trim($reason) === '') {
            throw new DomainException(
                'Rejecting a day needs a reason. The person worked or did not, and the record has to say which and why.'
            );
        }

        $record->update([
            'status' => DtrStatus::Rejected,
            'validated_at' => now(),
            'validated_by_user_id' => $by->getKey(),
            'remarks' => $reason,
        ]);

        return $record->refresh();
    }

    /**
     * Close a cutoff, holding whatever the site did not certify.
     *
     * @return Collection<int, DailyTimeRecord> the days that were held
     */
    public function closeCutoff(Employee $employee, CarbonInterface $periodStart, CarbonInterface $periodEnd): Collection
    {
        $uncertified = DailyTimeRecord::query()
            ->where('employee_id', $employee->getKey())
            ->where('status', DtrStatus::Unvalidated)
            ->whereBetween('work_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->get();

        foreach ($uncertified as $record) {
            $record->update([
                'status' => DtrStatus::Held,
                // Which cutoff it fell out of — what lets a later payslip say
                // "carried from" rather than showing an unexplained extra day.
                'held_from_period_end' => $periodEnd,
            ]);
        }

        return DailyTimeRecord::query()
            ->whereIn('id', $uncertified->pluck('id'))
            ->get();
    }

    /**
     * What a run for this period may pay.
     *
     * Two sets, and the second is the mechanic: days worked inside the period,
     * plus **held days from earlier periods that have since been certified**.
     * Anything already stamped as paid is excluded, which is what stops a day
     * being paid in its own cutoff and again as a carry-over.
     *
     * @return Collection<int, DailyTimeRecord>
     */
    public function payableFor(Employee $employee, CarbonInterface $periodStart, CarbonInterface $periodEnd): Collection
    {
        return DailyTimeRecord::query()
            ->where('employee_id', $employee->getKey())
            ->where('status', DtrStatus::Validated)
            ->whereNull('paid_in_period_end')
            ->where(function ($query) use ($periodStart, $periodEnd): void {
                $query
                    // Worked in this period.
                    ->whereBetween('work_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
                    // Or held out of an earlier one and certified since.
                    ->orWhere(function ($carried) use ($periodStart): void {
                        $carried->whereNotNull('held_from_period_end')
                            ->whereDate('held_from_period_end', '<', $periodStart->toDateString());
                    });
            })
            ->orderBy('work_date')
            ->get();
    }

    /**
     * Stamp days as paid in a period.
     *
     * @param  Collection<int, DailyTimeRecord>  $records
     */
    public function markPaid(Collection $records, CarbonInterface $periodEnd): void
    {
        DB::transaction(function () use ($records, $periodEnd): void {
            foreach ($records as $record) {
                $record->update([
                    'status' => DtrStatus::Paid,
                    'paid_in_period_end' => $periodEnd,
                ]);
            }
        });
    }

    /**
     * The DTR for a period, whatever state each day is in.
     *
     * @return Collection<int, DailyTimeRecord>
     */
    public function dtrFor(Employee $employee, CarbonInterface $periodStart, CarbonInterface $periodEnd): Collection
    {
        return DailyTimeRecord::query()
            ->where('employee_id', $employee->getKey())
            ->whereBetween('work_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->orderBy('work_date')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $row  one untrusted line of the export
     *
     * @throws DomainException when the row cannot become a day of work
     */
    private function importRow(Project $project, array $row, ?string $batchReference): void
    {
        $employeeNumber = (string) ($row['employee_number'] ?? '');

        $employee = Employee::query()
            ->where('employee_number', $employeeNumber)
            ->first();

        if ($employee === null) {
            throw new DomainException(sprintf('No employee with number "%s".', $employeeNumber));
        }

        try {
            $workDate = Carbon::parse((string) ($row['work_date'] ?? ''));
        } catch (Throwable) {
            throw new DomainException(sprintf('"%s" is not a date.', (string) ($row['work_date'] ?? '')));
        }

        // Slide 7's contract gate, reached from the timekeeping chain. The shift
        // happened either way, which is why this REJECTS the row and reports it
        // rather than discarding it.
        if (! $this->hiring->mayWork($employee, $workDate)) {
            throw new DomainException(sprintf(
                'Employee %s had no signed contract in force on %s.',
                $employee->employee_number,
                $workDate->toDateString(),
            ));
        }

        $in = Carbon::parse($workDate->toDateString().' '.((string) ($row['time_in'] ?? '')));
        $out = Carbon::parse($workDate->toDateString().' '.((string) ($row['time_out'] ?? '')));

        if ($out->lte($in)) {
            throw new DomainException(sprintf(
                'Punch out %s is not after punch in %s.',
                (string) ($row['time_out'] ?? ''),
                (string) ($row['time_in'] ?? ''),
            ));
        }

        $breakMinutes = (int) ($row['break_minutes'] ?? 0);
        $minutes = $in->diffInMinutes($out) - $breakMinutes;

        if ($minutes <= 0) {
            throw new DomainException('The break is longer than the shift.');
        }

        DB::transaction(function () use ($employee, $project, $workDate, $row, $breakMinutes, $minutes, $batchReference): void {
            $timelog = Timelog::query()->create([
                'employee_id' => $employee->getKey(),
                'project_id' => $project->getKey(),
                'work_date' => $workDate,
                'time_in' => (string) $row['time_in'],
                'time_out' => (string) $row['time_out'],
                'break_minutes' => $breakMinutes,
                'source' => 'biometrics',
                'batch_reference' => $batchReference,
            ]);

            DailyTimeRecord::query()->create([
                'employee_id' => $employee->getKey(),
                'project_id' => $project->getKey(),
                'timelog_id' => $timelog->getKey(),
                'work_date' => $workDate,
                'hours_worked' => bcdiv((string) $minutes, '60', 2),
                // Unvalidated is where every day starts. A DTR that arrived
                // pre-certified would make the site's certification a formality.
                'status' => DtrStatus::Unvalidated,
            ]);
        });
    }
}
