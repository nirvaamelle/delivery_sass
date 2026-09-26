<?php

namespace App\Domain\Procurement;

use DomainException;

/**
 * The issuance would drive the balance below zero.
 *
 * A negative stock balance is a fiction. If the card says 500 and the site
 * wants 600, either the count is wrong or the request is — and both need a
 * person, not a silent negative number that quietly corrects itself at the next
 * delivery.
 */
class InsufficientStockException extends DomainException {}
