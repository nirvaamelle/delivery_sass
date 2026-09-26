<?php

namespace App\Domain\Procurement;

use DomainException;

/**
 * The vendor may not receive this RFQ.
 *
 * PLAN.md §5: "expired vendors cannot receive an RFQ". Expired, suspended, or
 * never accredited — the vendor is real and looks ordinary in a list, which is
 * exactly why a human checking that list misses it.
 */
class VendorNotEligibleException extends DomainException {}
