<?php

namespace App\Domain\Billing;

/**
 * Slide 6's submission branch.
 *
 * `Returned` is a first-class state, not an error. PHASE-PLAN.md is explicit:
 * "the returned branch is a first-class path, not an error case" — the billing
 * comes back, keeps its number, gains a reason, and is resubmitted in the next
 * cutoff. Modelling it as a failure would lose the deductions that have to
 * survive into the next submission.
 */
enum BillingStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Returned = 'returned';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
