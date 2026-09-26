<?php

namespace App\Domain\Hris;

use App\Domain\Support\Money;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\PayrollLine;

/**
 * 13th month pay — one twelfth of the basic pay actually earned in the year.
 *
 * **Read from payroll lines, not from rates.** A rate says what somebody would
 * have earned; the lines say what they were paid. Only approved and released
 * registers count: a computed one can still change.
 *
 * **The basis is basic pay plus paid leave.** Overtime, night differential and
 * allowances are excluded.
 *
 * **This is the computation and the report, not the payout.** Whether it is
 * paid as its own run in December, with the last cutoff, or in two halves is the
 * client's policy, and the build does not pay it on anybody's behalf.
 *
 * PLACEHOLDER: benefits are not in PHASE-PLAN.md Part D (DECISIONS-PENDING.md,
 * unnumbered). The tax-exempt ceiling is `payroll.benefits.thirteenth_month.
 * tax_exempt_ceiling` and is applied to the 13th month alone — the build tracks
 * no other benefits that share the ceiling.
 */
class ThirteenthMonthService
{
    /** Registers that count toward the year. */
    private const COUNTED = [PayrollRunStatus::Approved, PayrollRunStatus::Released];

    /**
     * @return array{basis: string, amount: string, tax_exempt: string, taxable: string, lines: int}
     */
    public function forEmployee(Employee $employee, int $year): array
    {
        $lines = PayrollLine::query()
            ->where('employee_id', $employee->getKey())
            ->whereHas('run', fn ($query) => $query
                ->whereIn('status', array_map(fn (PayrollRunStatus $status): string => $status->value, self::COUNTED))
                ->whereYear('period_end', $year))
            ->get();

        // bcmath over decrypted values: the columns are ciphertext at rest.
        $basis = '0.0000';

        foreach ($lines as $line) {
            $basis = Money::sum($basis, (string) $line->basic_pay, (string) ($line->leave_pay ?? '0'));
        }

        $amount = bcdiv($basis, '12', Money::SCALE);
        $ceiling = bcadd((string) config('payroll.benefits.thirteenth_month.tax_exempt_ceiling', '90000.00'), '0', Money::SCALE);
        $exempt = bccomp($amount, $ceiling, Money::SCALE) > 0 ? $ceiling : $amount;

        return [
            'basis' => $basis,
            'amount' => $amount,
            'tax_exempt' => $exempt,
            'taxable' => bcsub($amount, $exempt, Money::SCALE),
            'lines' => $lines->count(),
        ];
    }

    /**
     * Everybody in the company paid at least once in the year, by employee number.
     *
     * @return array<int, array{employee_id: int, employee_number: string, name: string, basis: string, amount: string, tax_exempt: string, taxable: string, lines: int}>
     */
    public function forOrganization(Organization $organization, int $year): array
    {
        $rows = [];

        $employees = Employee::query()
            ->where('organization_id', $organization->getKey())
            ->whereHas('payrollLines.run', fn ($query) => $query->whereYear('period_end', $year))
            ->orderBy('employee_number')
            ->get();

        foreach ($employees as $employee) {
            $computed = $this->forEmployee($employee, $year);

            if ($computed['lines'] === 0) {
                continue;
            }

            $rows[] = [
                'employee_id' => (int) $employee->getKey(),
                'employee_number' => $employee->employee_number,
                'name' => $employee->fullName(),
                ...$computed,
            ];
        }

        return $rows;
    }
}
