<?php

namespace App\Domain\Hris;

use App\Models\DailyTimeRecord;
use App\Models\Employee;
use App\Models\OvertimeAuthority;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * Overtime and night differential — slide 7's "approved in writing".
 *
 * The failure this prevents is ordinary rather than fraudulent. A punch at 21:40
 * proves somebody was on site; it does not prove anybody asked them to be. Paying
 * every hour past eight at the premium makes the time clock the approval — and
 * the premium is a quarter on top, so the cost of nobody deciding is a quarter
 * more than the cost of somebody deciding.
 *
 * **Payable premium hours are the SMALLER of what was worked and what was
 * authorised**, every day. Both halves matter:
 *
 *   - worked but unauthorised is not paid at the premium. The hours are not
 *     deleted — the DTR still says twelve — they wait for an authority;
 *   - authorised but unworked is not paid either. An authority is a permission,
 *     not a timesheet.
 *
 * **Night hours come from the punches, never from the authority.** The punches
 * say when the work happened; the authority only says it may be paid.
 *
 * PLACEHOLDER: Part D item 9 and B3 — the standard day, the premiums and the
 * night window come from config/payroll.php.
 */
class OvertimeService
{
    /**
     * @throws DomainException when the hours are not within a day, or no reason is given
     */
    public function request(
        Employee $employee,
        Project $project,
        CarbonInterface $workDate,
        OvertimeType $type,
        string $hours,
        string $reason,
        ?User $by = null,
    ): OvertimeAuthority {
        if (bccomp($hours, '0', 2) <= 0 || bccomp($hours, '24', 2) > 0) {
            throw new DomainException(sprintf(
                'An authority for %s hours is not a working day. Premium hours must be more than zero and no more than twenty-four.',
                $hours,
            ));
        }

        if (trim($reason) === '') {
            throw new DomainException('An overtime request needs a reason — it is what the approver is being asked to agree to.');
        }

        return OvertimeAuthority::query()->create([
            'employee_id' => $employee->getKey(),
            'project_id' => $project->getKey(),
            'work_date' => $workDate,
            'type' => $type,
            'hours_authorised' => $hours,
            'reason' => $reason,
            'status' => OvertimeStatus::Requested,
            'requested_at' => now(),
            'requested_by_user_id' => $by?->getKey(),
        ]);
    }

    /**
     * Approve, in writing.
     *
     * @throws DomainException when already decided, nothing is in writing, or the
     *                         requester approves their own request
     */
    public function approve(OvertimeAuthority $authority, User $by, string $writtenReference, ?string $remarks = null): OvertimeAuthority
    {
        $this->assertPending($authority);

        if (trim($writtenReference) === '') {
            throw new DomainException(
                'Slide 7 requires overtime approved IN WRITING. Record the memo or form reference the approval rests on.'
            );
        }

        /*
         * A judgement beyond the slide, recorded as one: an authority approved by
         * its own requester is a request with a second click. The deck names the
         * timekeeper and the site engineer as different owners of these steps.
         */
        if ($authority->requested_by_user_id !== null && (int) $authority->requested_by_user_id === (int) $by->getKey()) {
            throw new DomainException('An overtime authority cannot be approved by the person who requested it.');
        }

        $authority->update([
            'status' => OvertimeStatus::Approved,
            'written_reference' => $writtenReference,
            'decided_at' => now(),
            'decided_by_user_id' => $by->getKey(),
            'decision_remarks' => $remarks,
        ]);

        return $authority->refresh();
    }

    /**
     * @throws DomainException when already decided or no reason is given
     */
    public function reject(OvertimeAuthority $authority, User $by, string $reason): OvertimeAuthority
    {
        $this->assertPending($authority);

        if (trim($reason) === '') {
            throw new DomainException('Rejecting an overtime request needs a reason the requester can act on.');
        }

        $authority->update([
            'status' => OvertimeStatus::Rejected,
            'decided_at' => now(),
            'decided_by_user_id' => $by->getKey(),
            'decision_remarks' => $reason,
        ]);

        return $authority->refresh();
    }

    /**
     * Overtime hours payable for one day: the smaller of worked and authorised.
     */
    public function payableOvertimeHours(DailyTimeRecord $record): string
    {
        $standard = (string) config('payroll.standard_hours_per_day', '8.00');
        $worked = bcsub((string) $record->hours_worked, $standard, 2);

        if (bccomp($worked, '0', 2) <= 0) {
            return '0.00';
        }

        return $this->smaller($worked, $this->authorisedHours($record, OvertimeType::Overtime));
    }

    /**
     * Night differential hours payable for one day: the smaller of night hours
     * worked (from the punches) and night hours authorised.
     */
    public function payableNightHours(DailyTimeRecord $record): string
    {
        $worked = $this->nightHoursWorked($record);

        if (bccomp($worked, '0', 2) <= 0) {
            return '0.00';
        }

        return $this->smaller($worked, $this->authorisedHours($record, OvertimeType::NightDifferential));
    }

    /**
     * Hours inside the night window, read from the punches.
     *
     * The importer refuses a punch-out before its punch-in, so a shift lies
     * within one calendar day and the window is its two same-day pieces:
     * 00:00–06:00 and 22:00–24:00. Breaks are not subtracted here — nothing
     * records WHEN a break was taken, and assuming it fell at night would
     * underpay the differential on exactly the shifts that earn it.
     */
    public function nightHoursWorked(DailyTimeRecord $record): string
    {
        $timelog = $record->timelog()->first();

        if ($timelog === null) {
            return '0.00';
        }

        $date = $record->work_date->toDateString();
        $in = Carbon::parse($date.' '.$timelog->time_in);
        $out = Carbon::parse($date.' '.$timelog->time_out);

        $window = config('payroll.night_window', ['start' => '22:00', 'end' => '06:00']);

        $minutes = $this->overlapMinutes($in, $out, Carbon::parse($date.' 00:00'), Carbon::parse($date.' '.$window['end']))
            + $this->overlapMinutes($in, $out, Carbon::parse($date.' '.$window['start']), Carbon::parse($date)->addDay()->startOfDay());

        return bcdiv((string) $minutes, '60', 2);
    }

    private function authorisedHours(DailyTimeRecord $record, OvertimeType $type): string
    {
        $authority = OvertimeAuthority::query()
            ->where('employee_id', $record->employee_id)
            ->whereDate('work_date', $record->work_date)
            ->where('type', $type)
            ->where('status', OvertimeStatus::Approved)
            ->first();

        return $authority === null ? '0.00' : (string) $authority->hours_authorised;
    }

    private function smaller(string $a, string $b): string
    {
        return bcadd(bccomp($a, $b, 2) <= 0 ? $a : $b, '0', 2);
    }

    private function overlapMinutes(Carbon $start, Carbon $end, Carbon $windowStart, Carbon $windowEnd): int
    {
        $from = $start->greaterThan($windowStart) ? $start : $windowStart;
        $to = $end->lessThan($windowEnd) ? $end : $windowEnd;

        return $to->greaterThan($from) ? (int) $from->diffInMinutes($to) : 0;
    }

    /**
     * @throws DomainException
     */
    private function assertPending(OvertimeAuthority $authority): void
    {
        if ($authority->status !== OvertimeStatus::Requested) {
            throw new DomainException(sprintf(
                'That overtime authority was already %s.',
                $authority->status->value,
            ));
        }
    }
}
