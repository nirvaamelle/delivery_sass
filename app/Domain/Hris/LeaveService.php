<?php

namespace App\Domain\Hris;

use App\Models\DailyTimeRecord;
use App\Models\Employee;
use App\Models\LeaveRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Service Incentive Leave — five paid days a year once a year of service is
 * complete.
 *
 * **The leave year runs from the hiring anniversary**, not the calendar year:
 * somebody hired in March earns their first five days next March, and those five
 * days are used by the following March.
 *
 * **Leave is paid once.** A payroll run stamps the record with its line, and a
 * paid record cannot be cancelled — the rule a DTR day already has.
 *
 * **Leave and work do not share a day.** A certified DTR with hours on the date
 * refuses the leave; a day cannot be paid twice by being both.
 *
 * PLACEHOLDER: benefits are not in PHASE-PLAN.md Part D (DECISIONS-PENDING.md,
 * unnumbered). The five days and twelve months follow the Labor Code's minimum;
 * the build does NOT carry unused days over or convert them to cash at year end,
 * because whether the client does either is policy the build will not invent.
 */
class LeaveService
{
    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}|null
     *                                                                  null while the employee is not yet entitled
     */
    public function leaveYearFor(Employee $employee, CarbonInterface $date): ?array
    {
        $hired = CarbonImmutable::parse($employee->date_hired)->startOfDay();
        $on = CarbonImmutable::parse($date)->startOfDay();

        if ($hired->addMonthsNoOverflow($this->monthsBeforeEntitled())->gt($on)) {
            return null;
        }

        $years = (int) floor($hired->diffInYears($on));
        $start = $hired->addYearsNoOverflow($years);

        return ['start' => $start, 'end' => $start->addYearNoOverflow()->subDay()];
    }

    /**
     * Days still available in the leave year that contains the date.
     */
    public function balanceOn(Employee $employee, CarbonInterface $date): string
    {
        $year = $this->leaveYearFor($employee, $date);

        if ($year === null) {
            return '0.0';
        }

        $used = '0.0';

        foreach ($this->activeRecords($employee)->whereBetween('leave_date', [$year['start']->toDateString(), $year['end']->toDateString()])->pluck('days') as $days) {
            $used = bcadd($used, (string) $days, 1);
        }

        return bcsub($this->daysPerYear(), $used, 1);
    }

    public function record(Employee $employee, CarbonInterface $date, string $days, ?string $reason = null, ?User $by = null): LeaveRecord
    {
        if (! in_array($days, ['1', '1.0', '0.5'], true)) {
            throw new DomainException('Leave is recorded as a whole day (1) or half a day (0.5).');
        }

        $days = bcadd($days, '0', 1);
        $on = CarbonImmutable::parse($date)->startOfDay();

        if ($on->lt($employee->date_hired) || ($employee->separated_on !== null && $on->gt($employee->separated_on))) {
            throw new DomainException(sprintf('%s was not employed on %s.', $employee->fullName(), $on->toDateString()));
        }

        if ($this->activeRecords($employee)->whereDate('leave_date', $on)->exists()) {
            throw new DomainException(sprintf('Leave is already recorded for %s on %s.', $employee->fullName(), $on->toDateString()));
        }

        $worked = DailyTimeRecord::query()
            ->where('employee_id', $employee->getKey())
            ->whereDate('work_date', $on)
            ->where('hours_worked', '>', 0)
            ->exists();

        if ($worked) {
            throw new DomainException(sprintf('%s has a time record with hours on %s. A day is either worked or on leave, never both.', $employee->fullName(), $on->toDateString()));
        }

        $year = $this->leaveYearFor($employee, $on);

        if ($year === null) {
            throw new DomainException(sprintf(
                '%s is not yet entitled to service incentive leave: it starts after %d months of service, on %s.',
                $employee->fullName(),
                $this->monthsBeforeEntitled(),
                CarbonImmutable::parse($employee->date_hired)->addMonthsNoOverflow($this->monthsBeforeEntitled())->toDateString(),
            ));
        }

        $balance = $this->balanceOn($employee, $on);

        if (bccomp($days, $balance, 1) > 0) {
            throw new DomainException(sprintf(
                'Only %s days of service incentive leave are left in the leave year %s to %s.',
                $balance,
                $year['start']->toDateString(),
                $year['end']->toDateString(),
            ));
        }

        $record = LeaveRecord::query()->create([
            'employee_id' => $employee->getKey(),
            'leave_type' => LeaveRecord::SERVICE_INCENTIVE,
            'leave_date' => $on->toDateString(),
            'days' => $days,
            'reason' => filled($reason) ? trim((string) $reason) : null,
            'recorded_by_user_id' => $by?->getKey(),
        ]);

        activity()->performedOn($employee)->causedBy($by)
            ->withProperties(['leave_date' => $on->toDateString(), 'days' => $days])
            ->log('leave-recorded');

        return $record;
    }

    public function cancel(LeaveRecord $record, string $reason, ?User $by = null): LeaveRecord
    {
        if ($record->payroll_line_id !== null) {
            throw new DomainException('This leave has already been paid by a payroll run and cannot be cancelled.');
        }

        if ($record->cancelled_at !== null) {
            throw new DomainException('This leave is already cancelled.');
        }

        if (trim($reason) === '') {
            throw new DomainException('Cancelling leave needs a reason.');
        }

        $record->update([
            'cancelled_at' => now(),
            'cancelled_by_user_id' => $by?->getKey(),
            'cancellation_reason' => trim($reason),
        ]);

        return $record;
    }

    /**
     * Leave not yet paid, up to the end of a cutoff — held leave is carried
     * forward the way a held DTR day is.
     *
     * @return Collection<int, LeaveRecord>
     */
    public function unpaidUpTo(Employee $employee, CarbonInterface $periodEnd): Collection
    {
        return $this->activeRecords($employee)
            ->whereNull('payroll_line_id')
            ->whereDate('leave_date', '<=', $periodEnd)
            ->orderBy('leave_date')
            ->get();
    }

    /**
     * @return Builder<LeaveRecord>
     */
    private function activeRecords(Employee $employee)
    {
        return LeaveRecord::query()
            ->where('employee_id', $employee->getKey())
            ->where('leave_type', LeaveRecord::SERVICE_INCENTIVE)
            ->whereNull('cancelled_at');
    }

    private function daysPerYear(): string
    {
        return bcadd((string) config('payroll.benefits.service_incentive_leave.days_per_year', '5'), '0', 1);
    }

    private function monthsBeforeEntitled(): int
    {
        return (int) config('payroll.benefits.service_incentive_leave.after_months_of_service', 12);
    }
}
