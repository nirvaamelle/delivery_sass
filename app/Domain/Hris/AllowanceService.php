<?php

namespace App\Domain\Hris;

use App\Domain\Support\Money;
use App\Models\Employee;
use App\Models\EmployeeAllowance;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Collection;

/**
 * Recurring allowances — granted from a date, ended on a date, never edited.
 *
 * **An allowance is a dated entry, like a rate.** Changing an amount in place
 * would restate every cutoff already paid at the old one, so a change is an end
 * and a new grant.
 *
 * PLACEHOLDER: benefits are not in PHASE-PLAN.md Part D at all (recorded as
 * unnumbered in DECISIONS-PENDING.md). The build assumes:
 *   - the amount is **per cutoff**, paid in full for any cutoff the allowance is
 *     in force on at least one day — no proration;
 *   - a **taxable** allowance is added to gross, so contributions and
 *     withholding are computed on it; a **non-taxable** one (de minimis) is
 *     added to net only;
 *   - two allowances with the same name may not overlap.
 */
class AllowanceService
{
    public function grant(
        Employee $employee,
        string $name,
        string $amount,
        bool $taxable,
        CarbonInterface $effectiveFrom,
        ?User $by = null,
    ): EmployeeAllowance {
        $name = trim($name);

        if ($name === '') {
            throw new DomainException('An allowance needs a name.');
        }

        if (! is_numeric($amount) || bccomp($amount, '0', Money::SCALE) <= 0) {
            throw new DomainException('An allowance needs an amount above zero.');
        }

        if ($effectiveFrom->lt($employee->date_hired)) {
            throw new DomainException(sprintf('An allowance cannot start before the employee was hired on %s.', $employee->date_hired->toDateString()));
        }

        $overlapping = EmployeeAllowance::query()
            ->where('employee_id', $employee->getKey())
            ->where('name', $name)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $effectiveFrom))
            ->exists();

        if ($overlapping) {
            throw new DomainException(sprintf('%s already has a "%s" allowance in force. End it before granting a new amount.', $employee->fullName(), $name));
        }

        $allowance = EmployeeAllowance::query()->create([
            'employee_id' => $employee->getKey(),
            'name' => $name,
            'amount' => bcadd($amount, '0', Money::SCALE),
            'taxable' => $taxable,
            'effective_from' => $effectiveFrom->toDateString(),
            'created_by_user_id' => $by?->getKey(),
        ]);

        // Which allowance, never how much.
        activity()->performedOn($employee)->causedBy($by)
            ->withProperties(['allowance' => $name, 'taxable' => $taxable, 'from' => $effectiveFrom->toDateString()])
            ->log('allowance-granted');

        return $allowance;
    }

    public function end(EmployeeAllowance $allowance, CarbonInterface $effectiveTo, ?User $by = null): EmployeeAllowance
    {
        if ($allowance->effective_to !== null) {
            throw new DomainException(sprintf('The "%s" allowance already ended on %s.', $allowance->name, $allowance->effective_to->toDateString()));
        }

        if ($effectiveTo->lt($allowance->effective_from)) {
            throw new DomainException('An allowance cannot end before it started.');
        }

        $allowance->update(['effective_to' => $effectiveTo->toDateString(), 'ended_by_user_id' => $by?->getKey()]);

        activity()->performedOn($allowance->employee()->sole())->causedBy($by)
            ->withProperties(['allowance' => $allowance->name, 'to' => $effectiveTo->toDateString()])
            ->log('allowance-ended');

        return $allowance;
    }

    /**
     * Allowances in force on at least one day of the period.
     *
     * @return Collection<int, EmployeeAllowance>
     */
    public function inForce(Employee $employee, CarbonInterface $periodStart, CarbonInterface $periodEnd): Collection
    {
        return EmployeeAllowance::query()
            ->where('employee_id', $employee->getKey())
            ->whereDate('effective_from', '<=', $periodEnd)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $periodStart))
            ->orderBy('effective_from')
            ->get();
    }

    /**
     * What one cutoff pays, split by tax treatment — summed in bcmath.
     *
     * @return array{taxable: string, non_taxable: string}
     */
    public function totalsFor(Employee $employee, CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        $totals = ['taxable' => '0.0000', 'non_taxable' => '0.0000'];

        foreach ($this->inForce($employee, $periodStart, $periodEnd) as $allowance) {
            $key = $allowance->taxable ? 'taxable' : 'non_taxable';
            $totals[$key] = Money::sum($totals[$key], (string) $allowance->amount);
        }

        return $totals;
    }
}
