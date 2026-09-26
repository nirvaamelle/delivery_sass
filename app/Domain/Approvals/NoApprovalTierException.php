<?php

namespace App\Domain\Approvals;

use DomainException;

/**
 * No tier's band contains the amount.
 *
 * Fatal rather than defaulting to the lowest tier, which would route a large
 * purchase to the smallest authority — the exact failure the matrix exists to
 * prevent. A gap in the bands is a configuration error and should read as one.
 */
class NoApprovalTierException extends DomainException {}
