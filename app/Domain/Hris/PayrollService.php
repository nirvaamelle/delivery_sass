<?php

namespace App\Domain\Hris;

use App\Domain\Gates\GateFailedException;
use App\Domain\Gates\Gatekeeper;
use App\Domain\Numbering\DocumentNumberGenerator;
use App\Domain\Support\Money;
use App\Jobs\ComputePayrollRun;
use App\Models\DailyTimeRecord;
use App\Models\Employee;
use App\Models\EmployeeRate;
use App\Models\LeaveRecord;
use App\Models\Organization;
use App\Models\PayrollLine;
use App\Models\PayrollLineDay;
use App\Models\PayrollRun;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The payroll run — P3-07 and P3-08.
 *
 * Slide 7: "Gross pay computed from VALIDATED DTR only", on a semi-monthly
 * cutoff, queued. Four rules, each of which prevents a specific way people end
 * up paid wrongly:
 *
 *   - **a run belongs to exactly one cutoff.** The 3rd to the 18th straddles two
 *     halves of the month, and every day in the overlap is payable by both;
 *   - **validated days only.** Closing the cutoff holds what the site did not
 *     certify (P3-05), and a held day certified late is paid in a later run —
 *     flagged as carried, so the payslip can say so;
 *   - **each day at the rate in force on THAT day.** A raise effective the 10th
 *     pays the 8th at the old rate however late the run is computed, and a
 *     carried day keeps the rate of the day it was worked;
 *   - **a missing rate is an exception, not a zero.** An employee the run cannot
 *     price is reported on the run and gets no line, because a zero line looks
 *     like a run that worked.
 *
 * The strongest guard is not in this class: `payroll_line_days` holds a unique
 * key on the DTR day, so the database refuses a day on two lines whatever code
 * path tries it. The filter below keeps a normal run from reaching that key; the
 * key is what holds if a future path forgets to.
 */
class PayrollService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbering,
        private readonly TimekeepingService $timekeeping,
        private readonly EmployeeService $employees,
        private readonly OvertimeService $overtime,
        private readonly StatutoryCalculator $statutory,
        private readonly Gatekeeper $gates,
        private readonly AllowanceService $allowances,
        private readonly LeaveService $leaves,
    ) {}

    /**
     * Open a run for one configured cutoff.
     *
     * @throws DomainException when the period is not exactly one cutoff
     */
    public function open(Organization $organization, CarbonInterface $periodStart, CarbonInterface $periodEnd, ?User $by = null): PayrollRun
    {
        $this->assertAlignedToCutoff($periodStart, $periodEnd);

        // Numbered inside the transaction, so a duplicate-period refusal takes
        // its document number back with it rather than leaving a hole.
        return DB::transaction(fn (): PayrollRun => PayrollRun::query()->create([
            'organization_id' => $organization->getKey(),
            'number' => $this->numbering->next('PAY'),
            'status' => PayrollRunStatus::Draft,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'opened_by_user_id' => $by?->getKey(),
        ]));
    }

    /**
     * Hand the computation to the queue.
     *
     * @throws DomainException when the run is not a draft
     */
    public function queue(PayrollRun $run): PayrollRun
    {
        if ($run->status !== PayrollRunStatus::Draft) {
            throw new DomainException(sprintf(
                'Payroll run %s is %s; only a draft can be queued.',
                $run->number,
                $run->status->value,
            ));
        }

        $run->update([
            'status' => PayrollRunStatus::Queued,
            'queued_at' => now(),
        ]);

        ComputePayrollRun::dispatch($run->getKey());

        return $run->refresh();
    }

    /**
     * Price every payable day and write the register.
     *
     * One transaction for the whole run: a computation that fails half-way
     * leaves the run exactly as it was, never a register with half its lines.
     *
     * @throws DomainException when the run has already been computed
     */
    public function compute(PayrollRun $run, ?User $by = null): PayrollRun
    {
        if (! in_array($run->status, [PayrollRunStatus::Draft, PayrollRunStatus::Queued], true)) {
            throw new DomainException(sprintf(
                'Payroll run %s is already %s. Recomputing would write a second set of lines against the same days.',
                $run->number,
                $run->status->value,
            ));
        }

        return DB::transaction(function () use ($run, $by): PayrollRun {
            $exceptions = [];
            $grossTotal = '0.0000';
            $netTotal = '0.0000';

            foreach ($this->employeesFor($run) as $employee) {
                // "DTR closes one day after cutoff": whatever the site has not
                // certified by now is held, not dropped.
                $this->timekeeping->closeCutoff($employee, $run->period_start, $run->period_end);

                $days = $this->timekeeping
                    ->payableFor($employee, $run->period_start, $run->period_end)
                    ->reject(fn (DailyTimeRecord $day): bool => PayrollLineDay::query()
                        ->where('daily_time_record_id', $day->getKey())
                        ->exists());

                if ($days->isEmpty()) {
                    // Leave pay posts against the projects days were worked on,
                    // so a cutoff with no worked day has nowhere to put it. Held,
                    // and said so, rather than paid off the books.
                    $heldLeave = $this->leaves->unpaidUpTo($employee, $run->period_end);

                    if ($heldLeave->isNotEmpty()) {
                        $exceptions[] = [
                            'employee_number' => $employee->employee_number,
                            'reason' => sprintf(
                                '%d leave day(s) held: no day was worked this cutoff, so there is no project to post the leave pay against. Paid with the next cutoff that has a worked day.',
                                $heldLeave->count(),
                            ),
                        ];
                    }

                    continue;
                }

                $priced = [];

                foreach ($days as $day) {
                    $rate = $this->employees->rateOn($employee, $day->work_date);

                    if ($rate === null) {
                        $exceptions[] = [
                            'employee_number' => $employee->employee_number,
                            'reason' => sprintf('No rate in force on %s. The day is certified and has not been paid.', $day->work_date->toDateString()),
                        ];

                        continue 2;
                    }

                    $priced[] = $this->price($day, $rate);
                }

                $line = $this->writeLine(
                    $run,
                    $employee,
                    $priced,
                    $this->pricedLeave($employee, $run, $exceptions),
                    $this->allowances->totalsFor($employee, $run->period_start, $run->period_end),
                    $exceptions,
                );

                if ($line === null) {
                    continue;
                }

                $grossTotal = Money::sum($grossTotal, (string) $line->gross_pay);
                $netTotal = Money::sum($netTotal, (string) $line->net_pay);
            }

            $run->update([
                'status' => PayrollRunStatus::Computed,
                'computed_at' => now(),
                'computed_by_user_id' => $by?->getKey(),
                'gross_total' => $grossTotal,
                'net_total' => $netTotal,
                'exceptions' => $exceptions,
            ]);

            return $run->refresh();
        });
    }

    /**
     * Who the run looks at: anybody employed at any point in the cutoff.
     *
     * Read from dates rather than the active flag. Somebody who resigned on the
     * 10th is owed for the 1st to the 10th, and a run that iterated only active
     * employees would never pay them.
     *
     * @return Collection<int, Employee>
     */
    private function employeesFor(PayrollRun $run): Collection
    {
        return Employee::query()
            ->where('organization_id', $run->organization_id)
            ->whereDate('date_hired', '<=', $run->period_end)
            ->where(function ($query) use ($run): void {
                $query->whereNull('separated_on')
                    ->orWhereDate('separated_on', '>=', $run->period_start);
            })
            ->orderBy('employee_number')
            ->get();
    }

    /**
     * Price one certified day.
     *
     * @return array{day: DailyTimeRecord, basic: string, overtime: string, night: string, amount: string, hours_regular: string, hours_overtime: string, hours_night: string, carried: bool}
     */
    private function price(DailyTimeRecord $day, EmployeeRate $rate): array
    {
        $standard = (string) config('payroll.standard_hours_per_day', '8.00');
        $hourly = $this->hourlyRate($rate);

        $worked = (string) $day->hours_worked;
        $regularHours = bccomp($worked, $standard, 2) < 0 ? $worked : $standard;

        // Premium hours are the smaller of worked and authorised — P3-06.
        $overtimeHours = $this->overtime->payableOvertimeHours($day);
        $nightHours = $this->overtime->payableNightHours($day);

        $basic = Money::round(bcmul($hourly, $regularHours, Money::WORKING_SCALE));

        $overtimeRate = bcmul($hourly, bcadd('1', (string) config('payroll.overtime_premium', '0.25'), Money::WORKING_SCALE), Money::WORKING_SCALE);
        $overtime = Money::round(bcmul($overtimeRate, $overtimeHours, Money::WORKING_SCALE));

        $nightRate = bcmul($hourly, (string) config('payroll.night_differential_rate', '0.10'), Money::WORKING_SCALE);
        $night = Money::round(bcmul($nightRate, $nightHours, Money::WORKING_SCALE));

        return [
            'day' => $day,
            'basic' => $basic,
            'overtime' => $overtime,
            'night' => $night,
            'amount' => Money::sum($basic, $overtime, $night),
            'hours_regular' => bcadd($regularHours, '0', 2),
            'hours_overtime' => $overtimeHours,
            'hours_night' => $nightHours,
            'carried' => $day->wasHeld(),
        ];
    }

    /**
     * Price the leave not yet paid, up to the end of the cutoff.
     *
     * A leave day is paid at the daily rate in force on that date: the hourly
     * rate times the standard day, times one or half. A day with no rate in force
     * is held and reported, the way a worked day with no rate is.
     *
     * @param  array<int, array{employee_number: string, reason: string}>  $exceptions
     * @return array{records: array<int, LeaveRecord>, pay: string, days: string}
     */
    private function pricedLeave(Employee $employee, PayrollRun $run, array &$exceptions): array
    {
        $standard = (string) config('payroll.standard_hours_per_day', '8.00');
        $priced = ['records' => [], 'pay' => '0.0000', 'days' => '0.0'];

        foreach ($this->leaves->unpaidUpTo($employee, $run->period_end) as $record) {
            $rate = $this->employees->rateOn($employee, $record->leave_date);

            if ($rate === null) {
                $exceptions[] = [
                    'employee_number' => $employee->employee_number,
                    'reason' => sprintf('No rate in force on %s. The leave day is held and has not been paid.', $record->leave_date->toDateString()),
                ];

                continue;
            }

            $daily = bcmul($this->hourlyRate($rate), $standard, Money::WORKING_SCALE);

            $priced['records'][] = $record;
            $priced['pay'] = Money::sum($priced['pay'], Money::round(bcmul($daily, (string) $record->days, Money::WORKING_SCALE)));
            $priced['days'] = bcadd($priced['days'], (string) $record->days, 1);
        }

        return $priced;
    }

    /**
     * The hourly rate, at full working precision.
     *
     * Kept unrounded until a day's pay is priced, so a monthly rate divided down
     * to an hour does not lose centavos on every hour of every day.
     */
    private function hourlyRate(EmployeeRate $rate): string
    {
        $standard = (string) config('payroll.standard_hours_per_day', '8.00');

        return match ($rate->basis) {
            PayBasis::Hourly => bcadd((string) $rate->rate, '0', Money::WORKING_SCALE),
            PayBasis::Daily => bcdiv((string) $rate->rate, $standard, Money::WORKING_SCALE),
            PayBasis::Monthly => bcdiv(
                bcdiv(bcmul((string) $rate->rate, '12', Money::WORKING_SCALE), (string) config('payroll.monthly_divisor', '261'), Money::WORKING_SCALE),
                $standard,
                Money::WORKING_SCALE,
            ),
        };
    }

    /**
     * Write one employee's line and its days.
     *
     * @param  array<int, array{day: DailyTimeRecord, basic: string, overtime: string, night: string, amount: string, hours_regular: string, hours_overtime: string, hours_night: string, carried: bool}>  $priced
     * @param  array{records: array<int, LeaveRecord>, pay: string, days: string}  $leave
     * @param  array{taxable: string, non_taxable: string}  $allowances
     * @param  array<int, array{employee_number: string, reason: string}>  $exceptions
     */
    private function writeLine(PayrollRun $run, Employee $employee, array $priced, array $leave, array $allowances, array &$exceptions): ?PayrollLine
    {
        $basic = Money::sum(...array_column($priced, 'basic'));
        $overtime = Money::sum(...array_column($priced, 'overtime'));
        $night = Money::sum(...array_column($priced, 'night'));

        // PLACEHOLDER (benefits, DECISIONS-PENDING.md): paid leave and taxable
        // allowances are compensation, so contributions and withholding are
        // computed on them; a non-taxable allowance is added after, to net only.
        $gross = Money::sum($basic, $overtime, $night, $leave['pay'], $allowances['taxable']);

        $deductions = $this->statutory->breakdown($gross);
        $net = Money::sum(bcsub($gross, $deductions['total'], Money::SCALE), $allowances['non_taxable']);

        if (bccomp($net, '0', Money::SCALE) < 0) {
            // A negative net is not a payslip. The contribution floors can exceed
            // a very short cutoff's pay, and how that is carried is an SOP
            // decision this build will not invent.
            $exceptions[] = [
                'employee_number' => $employee->employee_number,
                'reason' => sprintf('Deductions of %s exceed gross pay of %s. Carrying the balance is an SOP decision.', $deductions['total'], $gross),
            ];

            return null;
        }

        $line = PayrollLine::query()->create([
            'payroll_run_id' => $run->getKey(),
            'employee_id' => $employee->getKey(),
            'basic_pay' => $basic,
            'overtime_pay' => $overtime,
            'night_differential_pay' => $night,
            'leave_pay' => $leave['pay'],
            'leave_days' => $leave['days'],
            'taxable_allowances' => $allowances['taxable'],
            'non_taxable_allowances' => $allowances['non_taxable'],
            'gross_pay' => $gross,
            'sss_contribution' => $deductions['sss'],
            'philhealth_contribution' => $deductions['philhealth'],
            'pagibig_contribution' => $deductions['pagibig'],
            'withholding_tax' => $deductions['withholding'],
            'total_deductions' => $deductions['total'],
            'net_pay' => $net,
            'days_paid' => count($priced),
            'carried_days' => count(array_filter($priced, fn (array $p): bool => $p['carried'])),
        ]);

        // Stamped in the same transaction as the line: leave is paid once.
        foreach ($leave['records'] as $record) {
            $record->update(['payroll_line_id' => $line->getKey()]);
        }

        foreach ($priced as $p) {
            $line->days()->create([
                'daily_time_record_id' => $p['day']->getKey(),
                'project_id' => $p['day']->project_id,
                'work_date' => $p['day']->work_date,
                'carried' => $p['carried'],
                'hours_regular' => $p['hours_regular'],
                'hours_overtime' => $p['hours_overtime'],
                'hours_night' => $p['hours_night'],
                'amount' => $p['amount'],
            ]);
        }

        return $line;
    }

    /**
     * Approve the register — slide 7's step 7.
     *
     * F10's gate runs here, through the Gatekeeper like every other gate in the
     * system: no approval while any project's variance against the last cutoff is
     * unexplained.
     *
     * Approval, not computation, is what stamps a day as PAID. A computed run can
     * still be refused, and a day marked paid by a register that never went out is
     * a day the employee is never paid for.
     *
     * @throws DomainException when the run has not been computed
     * @throws GateFailedException when a variance is unexplained
     */
    public function approve(PayrollRun $run, User $by): PayrollRun
    {
        if ($run->status !== PayrollRunStatus::Computed) {
            throw new DomainException(sprintf(
                'Payroll run %s is %s. Only a computed register can be approved.',
                $run->number,
                $run->status->value,
            ));
        }

        $this->gates->assert($run, PayrollTransition::ApproveRegister->value);

        return DB::transaction(function () use ($run, $by): PayrollRun {
            $dayIds = PayrollLineDay::query()
                ->whereIn('payroll_line_id', $run->lines()->pluck('id'))
                ->pluck('daily_time_record_id');

            $this->timekeeping->markPaid(
                DailyTimeRecord::query()->whereIn('id', $dayIds)->get(),
                $run->period_end,
            );

            $run->update([
                'status' => PayrollRunStatus::Approved,
                'approved_at' => now(),
                'approved_by_user_id' => $by->getKey(),
            ]);

            return $run->refresh();
        });
    }

    /**
     * @throws DomainException when the period is not exactly one configured cutoff
     */
    private function assertAlignedToCutoff(CarbonInterface $start, CarbonInterface $end): void
    {
        if ($start->format('Y-m') === $end->format('Y-m')) {
            foreach (config('payroll.cutoffs', []) as $cutoff) {
                $endDay = $cutoff['end_day'] === 'last' ? $end->daysInMonth : (int) $cutoff['end_day'];

                if ($start->day === (int) $cutoff['start_day'] && $end->day === $endDay) {
                    return;
                }
            }
        }

        throw new DomainException(sprintf(
            'A payroll run for %s to %s is not one cutoff. Slide 7 cuts off on the 15th and at month end, and a run straddling both pays every overlapping day twice.',
            $start->toDateString(),
            $end->toDateString(),
        ));
    }
}
