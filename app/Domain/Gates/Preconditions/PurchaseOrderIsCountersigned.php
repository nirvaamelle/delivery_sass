<?php

namespace App\Domain\Gates\Preconditions;

use App\Domain\Gates\Precondition;
use App\Domain\Procurement\PurchaseOrderStatus;
use App\Models\PurchaseOrder;

/**
 * F2: no mobilization without a COUNTERSIGNED purchase order.
 *
 * The word matters, and it is the whole finding. Slide 3's obligation says a
 * purchase order can be approved internally and not yet countersigned by the
 * vendor, and that the mobilization gate tests the second. Approved means the
 * company decided to buy; countersigned means the supplier agreed to sell.
 *
 * A gate written against `Approved` would pass every order a vendor has not yet
 * accepted — and mobilization is expensive, immediate and hard to reverse: the
 * crew has travelled, the compound is up, the generator is hired.
 */
class PurchaseOrderIsCountersigned implements Precondition
{
    public function name(): string
    {
        return 'purchase-order-is-countersigned';
    }

    public function passes(object $subject): bool
    {
        if (! $subject instanceof PurchaseOrder) {
            return false;
        }

        return $subject->status === PurchaseOrderStatus::Countersigned;
    }

    public function failureMessage(object $subject): string
    {
        $status = $subject instanceof PurchaseOrder ? $subject->status->value : 'unknown';

        return sprintf(
            'The purchase order is %s, not countersigned. Approved is the company deciding to buy; countersigned is the vendor agreeing to sell, and mobilization follows the second.',
            $status,
        );
    }
}
