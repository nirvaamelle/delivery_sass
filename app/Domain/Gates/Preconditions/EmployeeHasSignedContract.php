<?php

namespace App\Domain\Gates\Preconditions;

use App\Domain\Gates\Precondition;
use App\Domain\Hris\ContractStatus;
use App\Models\Employee;

/**
 * Slide 7: "Employment contract signed before first shift."
 *
 * A gate rather than a filing instruction. Somebody who works an unsigned shift
 * has still worked it — the company owes them for it and owes the statutory
 * contributions on it, while holding nothing that says on what terms. Every
 * incentive on a busy site pushes toward letting them start and doing the
 * paperwork on Friday, which is precisely why this is enforced in software.
 *
 * The date is carried on the employee as `assertion_date` rather than passed
 * separately, because the Gatekeeper's contract takes one subject. A contract
 * signed in May for a June start does not authorise a May shift, and a
 * fixed-term one that expired in August does not authorise September.
 */
class EmployeeHasSignedContract implements Precondition
{
    public function name(): string
    {
        return 'employee-has-signed-contract';
    }

    public function passes(object $subject): bool
    {
        if (! $subject instanceof Employee) {
            return false;
        }

        $date = $subject->assertion_date ?? now();

        return $subject->employmentContracts()
            ->where('status', ContractStatus::Signed)
            ->whereDate('effective_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $date);
            })
            ->exists();
    }

    public function failureMessage(object $subject): string
    {
        $number = $subject instanceof Employee ? $subject->employee_number : 'unknown';

        return sprintf(
            'Employee %s has no signed employment contract in force on that date. A shift worked without one is still a shift the company owes for, on terms nothing records.',
            $number,
        );
    }
}
