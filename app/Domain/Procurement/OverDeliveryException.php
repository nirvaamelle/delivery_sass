<?php

namespace App\Domain\Procurement;

use DomainException;

/**
 * More arrived than was ordered.
 *
 * Not a windfall: it is stock nobody authorised paying for, and accepting it
 * quietly turns the purchase order into a suggestion. The three-way match in
 * P1-11 compares an invoice against the PO, so an over-receipt would either
 * fail the match later or, worse, pass it.
 */
class OverDeliveryException extends DomainException {}
