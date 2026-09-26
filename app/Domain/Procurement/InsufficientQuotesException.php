<?php

namespace App\Domain\Procurement;

use DomainException;

/**
 * Fewer quotes came back than PLAN.md §5 requires to award.
 *
 * The distinction that matters: P1-03 enforced the minimum on who was ASKED,
 * this enforces it on who ANSWERED. Inviting three and receiving one is not a
 * canvass — the abstract of canvass is a comparison, and a comparison of one is
 * a decision that was already made.
 */
class InsufficientQuotesException extends DomainException {}
