<?php

namespace App\Domain\Procurement;

use DomainException;

/**
 * The subcontractor holds no valid performance bond for the works period.
 *
 * F15: "Expired bond blocks a subcontract award." The vendor may be perfectly
 * accredited — the two documents expire on separate clocks, and a subcontract's
 * exposure is performance over months rather than a single delivery, so it is
 * the bond that has to be current and has to outlast the works.
 */
class ExpiredBondException extends DomainException {}
