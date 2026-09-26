<?php

namespace App\Domain\Requisitions;

/**
 * Requisition lifecycle.
 *
 * `Returned` is a distinct state from `Cancelled` because they mean different
 * things to the budget: a returned requisition is expected back in corrected
 * form, a cancelled one is not. Neither commits budget.
 */
enum RequisitionStatus: string
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

    /**
     * Does a requisition in this state lay claim to budget?
     *
     * Submitted and approved requisitions do. A draft has not been asked for
     * yet; a returned or cancelled one has been given back. Counting a returned
     * requisition would block the corrected one meant to replace it.
     */
    public function commitsBudget(): bool
    {
        return in_array($this, [self::Submitted, self::Approved], true);
    }
}
