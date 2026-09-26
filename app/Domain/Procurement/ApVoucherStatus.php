<?php

namespace App\Domain\Procurement;

/**
 * Where a payable sits between the match and the money leaving.
 *
 * `Raised` and `Approved` are separate because they are separate people: the
 * clerk who assembles the voucher from a match is not the officer who releases
 * it, and collapsing the two would make the authority matrix unreadable at the
 * one point where money actually moves. `Submitted` sits between them: the
 * voucher is with the approvers and no longer the clerk's to change.
 */
enum ApVoucherStatus: string
{
    case Raised = 'raised';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
