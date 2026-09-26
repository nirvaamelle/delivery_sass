<?php

namespace App\Domain\Procurement;

/**
 * Purchase order lifecycle.
 *
 * `Countersigned` is its own state, not an attachment. F2 gates mobilization on
 * a countersigned PO, which means the countersignature has to be something the
 * system can test rather than a scanned file somebody says is on record.
 */
enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Countersigned = 'countersigned';
    case Returned = 'returned';
    case Cancelled = 'cancelled';
    case Closed = 'closed';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
