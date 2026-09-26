<?php

namespace App\Domain\Approvals;

use DomainException;

/**
 * The tier being defined overlaps one already defined for the same document.
 *
 * An ambiguous authority matrix is worse than no matrix: it looks authoritative
 * while routing the same amount to two different levels depending on query
 * order.
 */
class OverlappingTierException extends DomainException {}
