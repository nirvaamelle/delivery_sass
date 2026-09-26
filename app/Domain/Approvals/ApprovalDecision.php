<?php

namespace App\Domain\Approvals;

/**
 * The state of one approval step.
 *
 * `Returned` is not a rejection. The deck's branch is approved-or-returned, and
 * a returned document comes back with a reason so it can be corrected and
 * resubmitted — which is also what stops the same item being billed twice.
 */
enum ApprovalDecision: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Returned = 'returned';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
