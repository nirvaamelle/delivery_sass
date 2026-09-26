<?php

namespace App\Domain\Mobilization;

/**
 * Gated transitions on a purchase order that concern mobilization.
 *
 * Named rather than stringly-typed: the Gatekeeper refuses an undeclared
 * transition outright, so a typo would otherwise enforce nothing at all,
 * silently, for as long as it took somebody to notice.
 */
enum MobilizationTransition: string
{
    case Mobilize = 'mobilize';
}
